<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use App\Contracts\RecordStore;
use App\Support\PrivateFiles;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class FinancialDocuments
{
    public function __construct(private RecordStore $store, private PrivateFiles $files) {}

    public function invoice(array $invoice): string
    {
        abort_unless(isset($invoice['snapshot']), 409, 'Issue the invoice before downloading it.');
        if (! empty($invoice['pdf_path'])) {
            return $this->files->read($invoice['pdf_path']);
        }
        $s = $invoice['snapshot'];
        $rows = '';
        foreach ($s['items'] as $item) {
            $rows .= '<tr><td>'.$this->e($item['description']).'</td><td>'.$this->e($item['quantity']).'</td><td>'.$this->money($item['unit_minor'], $s['currency']).'</td><td>'.$this->money($item['tax_minor'], $s['currency']).'</td><td>'.$this->money($item['total_minor'], $s['currency']).'</td></tr>';
        }
        $content = '<h1>Invoice '.$this->e($s['number']).'</h1><p>Issued '.$this->e(substr($s['issued_at'], 0, 10)).(! empty($s['due_at']) ? ' · Due '.$this->e(substr($s['due_at'], 0, 10)) : '').'</p><h3>Bill to</h3><p>'.$this->e($s['recipient']['name']).'<br>'.nl2br($this->e($s['recipient']['address'] ?? '')).'<br>'.$this->e($s['recipient']['tax_id'] ?? '').'</p><table><thead><tr><th>Description</th><th>Quantity</th><th>Unit</th><th>Tax</th><th>Total</th></tr></thead><tbody>'.$rows.'</tbody></table><div class="totals"><p>Subtotal '.$this->money($s['subtotal_minor'], $s['currency']).'</p><p>Discount '.$this->money($s['discount_minor'], $s['currency']).'</p><p>Tax '.$this->money($s['tax_minor'], $s['currency']).'</p><h2>Total '.$this->money($s['total_minor'], $s['currency']).'</h2></div><p>'.nl2br($this->e($s['notes'] ?? '')).'</p><h3>Payment instructions</h3><p>'.nl2br($this->e($s['business']['payment_instructions'] ?? '')).'</p><p>'.nl2br($this->e($s['business']['terms'] ?? '')).'</p>';
        $bytes = $this->render($s['business'], $s['design'], $content);

        return $this->persistPdf('invoices', $invoice['id'], $bytes);
    }

    public function payslip(array $slip): string
    {
        if (! empty($slip['pdf_path'])) {
            return $this->files->read($slip['pdf_path']);
        }
        $rows = '';
        foreach ($slip['earnings'] as $earning) {
            $rows .= '<tr><td>'.$this->e($earning['label']).'</td><td>Earning</td><td>'.$this->money($earning['amount_minor'], $slip['currency']).'</td></tr>';
        }
        foreach ($slip['deductions'] ?? [] as $deduction) {
            $rows .= '<tr><td>'.$this->e($deduction['label']).'</td><td>Deduction</td><td>'.$this->money($deduction['amount_minor'], $slip['currency']).'</td></tr>';
        }
        $content = '<h1>Salary statement</h1><p>Period '.$this->e($slip['period']).'</p><h3>'.$this->e($slip['name']).'</h3><table><thead><tr><th>Description</th><th>Type</th><th>Amount</th></tr></thead><tbody>'.$rows.'</tbody></table><div class="totals"><p>Gross '.$this->money($slip['gross_minor'], $slip['currency']).'</p><p>Deductions '.$this->money($slip['deductions_minor'], $slip['currency']).'</p><h2>Net pay '.$this->money($slip['net_minor'], $slip['currency']).'</h2></div><p>This salary statement records the released payroll calculation. Payment confirmation is recorded separately in your employee portal.</p>';

        return $this->persistPdf('payslips', $slip['id'], $this->render($slip['business'], $slip['design'], $content));
    }

    public function receipt(array $payment, array $invoice): string
    {
        $content = '<h1>Payment receipt</h1><p>'.$this->e($payment['receipt_number']).'</p><p>Invoice '.$this->e($invoice['number']).'</p><p>Received from '.$this->e($invoice['snapshot']['recipient']['name']).'</p><h2>'.$this->money($payment['amount_minor'], $payment['currency']).'</h2><p>Method '.$this->e($payment['method']).'<br>Reference '.$this->e($payment['reference']).'<br>Confirmed '.$this->e($payment['confirmed_at']).'</p>';

        return $this->render($invoice['snapshot']['business'], $invoice['snapshot']['design'], $content);
    }

    public function credit(array $credit, array $invoice): string
    {
        $content = '<h1>Credit note '.$this->e($credit['number']).'</h1><p>Original invoice '.$this->e($invoice['number']).'</p><h3>'.$this->e($credit['recipient']['name']).'</h3><p>'.nl2br($this->e($credit['reason'])).'</p><h2>Credit '.$this->money($credit['amount_minor'], $credit['currency']).'</h2><p>Issued '.$this->e(substr($credit['issued_at'], 0, 10)).'</p><p>This credit note adjusts the invoice balance. Any repayment is recorded separately.</p>';

        return $this->render($credit['business'], $invoice['snapshot']['design'], $content);
    }

    public function preview(array $business, array $design): string
    {
        return $this->render($business, $design, '<h1>Invoice preview</h1><p>Sample recipient</p><table><thead><tr><th>Description</th><th>Quantity</th><th>Total</th></tr></thead><tbody><tr><td>Professional services</td><td>1</td><td>100.00</td></tr></tbody></table><h2>Total 100.00</h2><p>'.nl2br($this->e($business['payment_instructions'] ?? '')).'</p>');
    }

    public function render(array $business, array $design, string $content): string
    {
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/D', $design['accent'] ?? '') ? $design['accent'] : '#174D3B';
        $template = in_array($design['template'] ?? '', ['classic', 'modern', 'compact'], true) ? $design['template'] : 'classic';
        $font = in_array($design['font'] ?? '', ['dejavusans', 'dejavuserif', 'freesans', 'freeserif'], true) ? $design['font'] : 'dejavusans';
        $margin = max(8, min(30, (int) ($design['margin_mm'] ?? 15)));
        $temp = storage_path('framework/cache/mpdf');
        if (! is_dir($temp)) {
            mkdir($temp, 0700, true);
        }
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => ($design['paper'] ?? 'A4') === 'Letter' ? 'Letter' : 'A4', 'default_font' => $font, 'tempDir' => $temp, 'margin_left' => $margin, 'margin_right' => $margin, 'margin_top' => $margin, 'margin_bottom' => $margin, 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetTitle('Financial document');
        $pdf->SetHTMLFooter('<div style="font-size:8pt;text-align:center">Page {PAGENO} of {nbpg}</div>');
        $size = $template === 'compact' ? '9pt' : '10pt';
        $header = $template === 'modern' ? "background:$accent;color:#ffffff;padding:16px" : "border-bottom:2px solid $accent;padding-bottom:14px";
        $css = "body{font-size:$size;color:#202520}h1{font-size:23pt;color:$accent}h2{font-size:15pt}h3{font-size:11pt}table{border-collapse:collapse;width:100%;margin:15px 0}th{text-align:left;background:#edf1ed;padding:9px}td{border-bottom:1px solid #d7ded7;padding:9px;vertical-align:top}thead{display:table-header-group}.totals{text-align:right;margin-top:14px}.letterhead{{$header}}";
        $logo = '';
        if (! empty($business['logo_data']) && strlen($business['logo_data']) <= 90000 && preg_match('/^[A-Za-z0-9+\/=]+$/D', $business['logo_data'])) {
            $logo = '<img style="max-width:60mm;max-height:20mm" src="data:image/png;base64,'.$business['logo_data'].'"><br>';
        }
        $letterhead = '<div class="letterhead">'.$logo.'<strong>'.$this->e($business['legal_name'] ?? 'Business name').'</strong><br>'.nl2br($this->e($business['address'] ?? '')).'<br>'.$this->e($business['tax_id'] ?? '').' '.$this->e($business['registration_id'] ?? '').'<br>'.$this->e($business['email'] ?? '').' '.$this->e($business['phone'] ?? '').'</div>';
        $signature = ! empty($business['signature']) ? '<p style="margin-top:24px">Authorized by<br>'.$this->e($business['signature']).'</p>' : '';
        $pdf->WriteHTML('<style>'.$css.'</style>'.$letterhead.$content.$signature);

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    private function persistPdf(string $collection, string $id, string $bytes): string
    {
        $path = $this->files->write($bytes, 'financial');
        $hash = hash('sha256', $bytes);
        try {
            $chosen = $this->store->transaction(function () use ($collection, $id, $path, $hash) {
                $record = $this->store->get($collection, $id);
                if (! empty($record['pdf_path'])) {
                    return $record['pdf_path'];
                }
                $this->store->put($collection, $id, array_replace($record, ['pdf_path' => $path, 'pdf_sha256' => $hash]), $record['version']);

                return $path;
            });
        } catch (\Throwable $e) {
            $this->files->delete($path);
            throw $e;
        }
        if ($chosen !== $path) {
            $this->files->delete($path);

            return $this->files->read($chosen);
        }

        return $bytes;
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function money(string $minor, string $currency): string
    {
        $exponent = in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 0 : (in_array($currency, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], true) ? 3 : 2);
        $digits = str_pad($minor, $exponent + 1, '0', STR_PAD_LEFT);

        return $this->e($currency.' '.($exponent ? substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent) : $digits));
    }
}
