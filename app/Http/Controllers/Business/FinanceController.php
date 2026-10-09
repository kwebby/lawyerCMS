<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Business;

use App\Contracts\RecordStore;
use App\Domain\Finance\FinancialDocuments;
use App\Domain\Finance\InvoiceService;
use App\Domain\Finance\PaymentService;
use App\Domain\Finance\PayrollService;
use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FinanceController extends Controller
{
    public function __construct(private InvoiceService $invoices, private PayrollService $payroll, private PaymentService $payments, private FinancialDocuments $documents, private RecordStore $store, private Access $access, private Audit $audit) {}

    public function invoices(Request $r)
    {
        return response()->json(['data' => array_map(fn ($invoice) => $this->access->present($r->user(), 'invoices', $invoice), $this->invoices->list($r->user()))]);
    }

    public function invoice(Request $r, string $id)
    {
        return response()->json(['data' => $this->access->present($r->user(), 'invoices', $this->invoices->find($r->user(), $id))]);
    }

    public function createInvoice(Request $r)
    {
        return response()->json(['data' => $this->invoices->save($r->user(), $r->all())], 201);
    }

    public function updateInvoice(Request $r, string $id)
    {
        return response()->json(['data' => $this->invoices->save($r->user(), $r->all(), $id)]);
    }

    public function issueInvoice(Request $r, string $id)
    {
        $invoice = $this->invoices->issue($r->user(), $id);
        $this->documents->invoice($invoice);

        return response()->json(['data' => $this->invoices->find($r->user(), $id)]);
    }

    public function invoicePdf(Request $r, string $id)
    {
        $invoice = $this->invoices->find($r->user(), $id);
        $this->audit->log($r->user()->id, 'invoice.downloaded', 'invoices', $id);

        return $this->pdf($this->documents->invoice($invoice), ($invoice['number'] ?? $id).'.pdf');
    }

    public function payInvoice(Request $r, string $id)
    {
        return response()->json(['data' => $this->invoices->payment($r->user(), $id, $r->all())], 201);
    }

    public function creditInvoice(Request $r, string $id)
    {
        return response()->json(['data' => $this->invoices->credit($r->user(), $id, $r->all())], 201);
    }

    public function invoiceHistory(Request $r, string $id)
    {
        $this->invoices->find($r->user(), $id);

        $history = [];
        foreach (['payments', 'credit_notes', 'refunds'] as $collection) {
            $history[$collection] = array_map(fn ($record) => $this->access->present($r->user(), $collection, $record), $this->store->query($collection, ['invoice_id' => $id], 500));
        }

        return response()->json(['data' => $history]);
    }

    public function creditPdf(Request $r, string $id)
    {
        $credit = $this->store->get('credit_notes', $id);
        abort_unless($credit !== null, 404);
        $invoice = $this->invoices->find($r->user(), $credit['invoice_id']);
        $this->audit->log($r->user()->id, 'credit_note.downloaded', 'credit_notes', $id);

        return $this->pdf($this->documents->credit($credit, $invoice), $credit['number'].'.pdf');
    }

    public function gateways(Request $r)
    {
        return response()->json(['data' => $this->payments->gateways()]);
    }

    public function checkout(Request $r, string $id)
    {
        return response()->json(['data' => $this->payments->checkout($r->user(), $id, $r->all())], 201);
    }

    public function reconcile(Request $r, string $id)
    {
        return response()->json(['data' => $this->payments->reconcile($r->user(), $id)]);
    }

    public function refund(Request $r, string $id)
    {
        return response()->json(['data' => $this->payments->refund($r->user(), $id, $r->all())]);
    }

    public function webhook(Request $r, string $provider)
    {
        $headers = [];
        foreach ($r->headers->all() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        return response()->json($this->payments->webhook($provider, $r->getContent(), $headers));
    }

    public function receipt(Request $r, string $id)
    {
        $payment = $this->store->get('payments', $id);
        abort_unless($payment !== null, 404);
        $invoice = $this->invoices->find($r->user(), $payment['invoice_id']);
        $this->audit->log($r->user()->id, 'receipt.downloaded', 'payments', $id);

        return $this->pdf($this->documents->receipt($payment, $invoice), 'receipt-'.$id.'.pdf');
    }

    public function payrollRuns(Request $r)
    {
        return response()->json(['data' => $this->payroll->list($r->user())]);
    }

    public function createPayroll(Request $r)
    {
        return response()->json(['data' => $this->payroll->save($r->user(), $r->all())], 201);
    }

    public function updatePayroll(Request $r, string $id)
    {
        return response()->json(['data' => $this->payroll->save($r->user(), $r->all(), $id)]);
    }

    public function transitionPayroll(Request $r, string $id, string $action)
    {
        $run = $this->payroll->transition($r->user(), $id, $action);
        if ($action === 'release') {
            foreach ($this->store->each('payslips', ['run_id' => $id]) as $slip) {
                $this->documents->payslip($slip);
            }
        }

        return response()->json(['data' => $run]);
    }

    public function payslips(Request $r)
    {
        return response()->json(['data' => $this->payroll->slips($r->user())]);
    }

    public function payslipPdf(Request $r, string $id)
    {
        $slip = $this->payroll->slip($r->user(), $id);
        $this->audit->log($r->user()->id, 'payslip.downloaded', 'payslips', $id);

        return $this->pdf($this->documents->payslip($slip), 'salary-'.$slip['period'].'.pdf');
    }

    public function payslipPayment(Request $r, string $id)
    {
        return response()->json(['data' => $this->payroll->confirmPayment($r->user(), $id, $r->all())]);
    }

    public function payrollCsv(Request $r, string $id)
    {
        $run = $this->payroll->find($r->user(), $id);
        $this->audit->log($r->user()->id, 'payroll.exported', 'payroll_runs', $id);

        return response()->streamDownload(function () use ($run) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['employee_id', 'name', 'period', 'currency', 'gross_minor', 'deductions_minor', 'net_minor'], ',', '"', '');
            foreach ($run['employees'] as $employee) {
                $values = [$employee['employee_id'], $employee['name'], $run['period'], $run['currency'], $employee['gross_minor'], $employee['deductions_minor'], $employee['net_minor']];
                $values = array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v, $values);
                fputcsv($f, $values, ',', '"', '');
            }
            fclose($f);
        }, 'payroll-'.$run['period'].'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function design(Request $r)
    {
        $this->access->authorize($r->user(), 'settings.read');

        return response()->json(['data' => $this->store->get('settings', 'invoice_design') ?? ['template' => 'classic', 'paper' => 'A4', 'accent' => '#174D3B', 'font' => 'dejavusans', 'margin_mm' => 15]]);
    }

    public function saveDesign(Request $r)
    {
        $this->access->authorize($r->user(), 'settings.write');
        $data = $r->validate(['template' => ['required', Rule::in(['classic', 'modern', 'compact'])], 'paper' => ['required', Rule::in(['A4', 'Letter'])], 'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'], 'font' => ['required', Rule::in(['dejavusans', 'dejavuserif', 'freesans', 'freeserif'])], 'margin_mm' => 'required|integer|min:8|max:30']);
        $saved = $this->store->transaction(function () use ($data, $r) {
            $current = $this->store->get('settings', 'invoice_design');
            $saved = $current ? $this->store->put('settings', 'invoice_design', $data, $current['version']) : $this->store->create('settings', $data, 'invoice_design');
            $this->audit->log($r->user()->id, 'invoice_design.saved', 'settings', 'invoice_design');

            return $saved;
        });

        return response()->json(['data' => $saved]);
    }

    public function previewDesign(Request $r)
    {
        $this->access->authorize($r->user(), 'settings.read');

        return $this->pdf($this->documents->preview($this->store->get('settings', 'business') ?? [], $this->store->get('settings', 'invoice_design') ?? []), 'invoice-preview.pdf');
    }

    private function pdf(string $bytes, string $filename)
    {
        return response($bytes)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.preg_replace('/[^A-Za-z0-9_.-]/', '-', $filename).'"')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('X-Content-Type-Options', 'nosniff');
    }
}
