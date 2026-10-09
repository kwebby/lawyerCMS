<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Approvals;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class InvoiceService
{
    public function __construct(private RecordStore $store, private Access $access, private Audit $audit, private Outbox $outbox, private Approvals $approvals) {}

    public function list($user): array
    {
        if (! in_array('client', $user->roles, true) && ! in_array('prospect', $user->roles, true)) {
            $this->access->authorize($user, 'invoices.read');
        }

        return $this->access->filter($user, 'invoices.read', $this->store->each('invoices'));
    }

    public function find($user, string $id, string $action = 'read'): array
    {
        $invoice = $this->store->get('invoices', $id);
        abort_unless($invoice !== null, 404);
        $this->access->authorize($user, 'invoices.'.$action, $invoice);

        return $invoice;
    }

    public function save($user, array $input, ?string $id = null): array
    {
        $old = $id ? $this->find($user, $id, 'write') : null;
        $this->access->authorize($user, 'invoices.write', $old);
        abort_if($old && $old['status'] !== 'draft', 409, 'Issued invoices are immutable. Use a credit note or a new invoice.');
        $data = Validator::make($input, [
            'version' => $id ? 'required|integer|min:1' : 'prohibited', 'recipient' => 'required|array:name,email,address,tax_id', 'recipient.name' => 'required|string|max:250', 'recipient.email' => 'sometimes|nullable|email|max:254', 'recipient.address' => 'sometimes|nullable|string|max:3000', 'recipient.tax_id' => 'sometimes|nullable|string|max:150',
            'currency' => ['required', Currency::rule()], 'items' => 'required|array|min:1|max:100', 'items.*' => 'required|array:description,quantity,unit_minor,tax_bps', 'items.*.description' => 'required|string|max:2000', 'items.*.quantity' => 'sometimes|numeric', 'items.*.unit_minor' => 'required', 'items.*.tax_bps' => 'sometimes|integer|min:0|max:10000', 'discount_minor' => 'sometimes', 'matter_id' => 'sometimes|nullable|string|max:100', 'client_ids' => 'sometimes|array|max:50', 'client_ids.*' => 'string|max:100', 'due_at' => 'sometimes|nullable|date', 'notes' => 'sometimes|nullable|string|max:10000', 'fee_type' => ['sometimes', Rule::in(['hourly', 'fixed', 'milestone', 'recurring', 'appearance', 'expense'])], 'template' => ['sometimes', Rule::in(['classic', 'modern', 'compact'])],
        ])->validate();
        if (! empty($data['matter_id'])) {
            $matter = $this->store->get('matters', $data['matter_id']);
            abort_unless($matter !== null, 422, 'Unknown matter.');
            $this->access->authorize($user, 'invoices.write', $matter);
            $data['team_ids'] = array_values(array_unique(array_filter(array_merge([$matter['owner_id'] ?? null], $matter['team_ids'] ?? []))));
            $data['denied_user_ids'] = $matter['denied_user_ids'] ?? [];
            $data['confidentiality'] = $matter['confidentiality'] ?? 'standard';
        }
        foreach ($data['client_ids'] ?? [] as $clientId) {
            $client = $this->store->get('users', $clientId);
            abort_unless($client && in_array('client', $client['roles'] ?? [], true) && ! empty($client['email_verified_at']), 422, 'Invoice recipients must be verified client accounts.');
        }
        abort_if(strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 131072, 422, 'This financial record exceeds the portable 128 KiB limit. Split it into smaller documents.');
        $version = $data['version'] ?? null;
        unset($data['version']);
        $totals = Money::calculate($data['items'], $data['discount_minor'] ?? '0');
        $invoice = array_replace($old ?? ['owner_id' => $user->id, 'team_ids' => [], 'client_ids' => [], 'status' => 'draft', 'visibility' => 'internal', 'paid_minor' => '0', 'credited_minor' => '0', 'refunded_minor' => '0'], $data, $totals);

        return $this->store->transaction(function () use ($user, $id, $invoice, $version) {
            if ($id) {
                abort_unless(($this->store->get('invoices', $id)['status'] ?? '') === 'draft', 409, 'Issued invoices are immutable.');
            }
            $saved = $id ? $this->store->put('invoices', $id, $invoice, $version) : $this->store->create('invoices', $invoice);
            $this->audit->log($user->id, 'invoice.draft_saved', 'invoices', $saved['id']);

            return $saved;
        });
    }

    public function issue($user, string $id): array
    {
        $this->find($user, $id, 'issue');

        return $this->store->transaction(function () use ($user, $id) {
            $invoice = $this->store->get('invoices', $id);
            if ($invoice['status'] !== 'draft') {
                return $invoice;
            }
            $business = $this->store->get('settings', 'business') ?? [];
            abort_unless(! empty($business['legal_name']) && ! empty($business['address']), 422, 'Set business legal name and address before issuing invoices.');
            $prefix = preg_replace('/[^A-Za-z0-9-]/', '', $business['invoice_prefix'] ?? 'INV') ?: 'INV';
            $year = now()->format('Y');
            $sequenceId = hash('sha256', $prefix.'-'.$year);
            $sequence = $this->store->get('invoice_sequences', $sequenceId);
            $next = (int) ($sequence['next'] ?? 1);
            $sequence ? $this->store->put('invoice_sequences', $sequenceId, ['next' => $next + 1], $sequence['version']) : $this->store->create('invoice_sequences', ['next' => 2], $sequenceId);
            $business = array_intersect_key($business, array_flip(['legal_name', 'trading_name', 'address', 'tax_id', 'registration_id', 'email', 'phone', 'payment_instructions', 'terms', 'logo_file_id', 'logo_data', 'signature', 'currency']));
            $design = $this->store->get('settings', 'invoice_design') ?? ['template' => 'classic', 'paper' => 'A4', 'accent' => '#174D3B', 'font' => 'dejavusans', 'margin_mm' => 15];
            if (! empty($invoice['template'])) {
                $design['template'] = $invoice['template'];
            }
            $snapshot = array_merge($invoice, ['number' => $prefix.'-'.$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT), 'business' => $business, 'design' => $design, 'issued_at' => now()->toIso8601String()]);
            unset($snapshot['id'], $snapshot['version'], $snapshot['created_at'], $snapshot['updated_at']);
            $invoice = $this->store->put('invoices', $id, array_replace($invoice, ['status' => 'issued', 'visibility' => 'shared', 'number' => $snapshot['number'], 'issued_at' => $snapshot['issued_at'], 'issued_by' => $user->id, 'snapshot' => $snapshot]), $invoice['version']);
            $this->audit->log($user->id, 'invoice.issued', 'invoices', $id, ['number' => $invoice['number']]);
            $this->outbox->enqueue('invoice.issued', ['invoice_id' => $id], 'invoice-issued-'.$id);

            return $invoice;
        });
    }

    public function payment($user, string $id, array $input): array
    {
        $this->find($user, $id, 'write');
        $data = Validator::make($input, ['amount_minor' => 'required', 'method' => ['required', Rule::in(['bank_transfer', 'cash'])], 'reference' => 'required|string|max:500', 'idempotency_key' => 'required|string|min:8|max:120'])->validate();

        return $this->allocate($id, $data['amount_minor'], $data['method'], $data['reference'], $data['idempotency_key'], $user->id);
    }

    /** Shared atomic boundary for verified provider captures and manually confirmed receipts. */
    public function allocate(string $invoiceId, mixed $amount, string $method, string $reference, string $idempotencyKey, ?string $actorId = null, ?string $currency = null): array
    {
        $amount = Money::minor($amount, allowZero: false);
        $paymentId = hash('sha256', $method.':'.$idempotencyKey);
        // Manual receipts share one key space so a key replayed with the other method is detected as well.
        $keyIds = in_array($method, ['bank_transfer', 'cash'], true) ? [hash('sha256', 'bank_transfer:'.$idempotencyKey), hash('sha256', 'cash:'.$idempotencyKey)] : [$paymentId];

        return $this->store->transaction(function () use ($invoiceId, $amount, $method, $reference, $paymentId, $keyIds, $actorId, $currency) {
            $invoice = $this->store->get('invoices', $invoiceId);
            foreach ($keyIds as $keyId) {
                if ($previous = $this->store->get('payments', $keyId)) {
                    abort_unless($previous['invoice_id'] === $invoiceId && $previous['amount_minor'] === (string) $amount && $previous['method'] === $method && $previous['reference'] === $reference, 409, 'This payment request key was already used for a payment with different details (amount, method or reference). Reload the invoice before recording another payment.');

                    return $previous;
                }
            }
            abort_unless($invoice && $invoice['status'] !== 'draft', 409, 'Only issued invoices can receive payment.');
            abort_if($currency && $currency !== $invoice['currency'], 422, 'Payment currency does not match the invoice.');
            $outstanding = (int) $invoice['total_minor'] - (int) $invoice['paid_minor'] - (int) ($invoice['credited_minor'] ?? '0');
            abort_if($amount > $outstanding, 409, 'Payment exceeds the outstanding invoice balance.');
            $paid = (int) $invoice['paid_minor'] + $amount;
            $payment = $this->store->create('payments', ['invoice_id' => $invoiceId, 'owner_id' => $invoice['owner_id'], 'client_ids' => $invoice['client_ids'], 'team_ids' => $invoice['team_ids'] ?? [], 'amount_minor' => (string) $amount, 'currency' => $invoice['currency'], 'method' => $method, 'reference' => $reference, 'confirmed_by' => $actorId, 'confirmed_at' => now()->toIso8601String(), 'refunded_minor' => '0', 'receipt_number' => $invoice['number'].'-R-'.substr($paymentId, 0, 10)], $paymentId);
            $this->store->put('invoices', $invoiceId, array_replace($invoice, ['paid_minor' => (string) $paid, 'status' => $paid + (int) ($invoice['credited_minor'] ?? '0') === (int) $invoice['total_minor'] ? 'paid' : 'part_paid']), $invoice['version']);
            $this->audit->log($actorId, 'invoice.payment_allocated', 'payments', $payment['id'], ['invoice_id' => $invoiceId]);
            $this->outbox->enqueue('payment.received', ['payment_id' => $payment['id'], 'invoice_id' => $invoiceId], 'payment-'.$paymentId);

            return $payment;
        });
    }

    public function credit($user, string $invoiceId, array $input): array
    {
        $this->find($user, $invoiceId, 'issue');
        $data = Validator::make($input, ['amount_minor' => 'required', 'reason' => 'required|string|max:2000', 'idempotency_key' => 'required|string|min:8|max:120'])->validate();
        $amount = Money::minor($data['amount_minor'], allowZero: false);
        $creditId = hash('sha256', $invoiceId.':'.$data['idempotency_key']);

        return $this->store->transaction(function () use ($user, $invoiceId, $data, $amount, $creditId) {
            $invoice = $this->store->get('invoices', $invoiceId);
            if ($credit = $this->store->get('credit_notes', $creditId)) {
                abort_unless($credit['amount_minor'] === (string) $amount && $credit['reason'] === $data['reason'], 409, 'Idempotency key reused for different credit details.');

                return $credit;
            }
            abort_if($invoice['status'] === 'draft', 409, 'Issue an invoice before creating a credit note.');
            $this->approvals->ensureIndependent($user, [$invoice['issued_by'] ?? null], 'credit note: you issued the original invoice', 'invoices', $invoiceId);
            $credited = (int) ($invoice['credited_minor'] ?? '0') + $amount;
            abort_if($credited > (int) $invoice['total_minor'], 422, 'Credit notes cannot exceed the original invoice total.');
            $credit = $this->store->create('credit_notes', ['invoice_id' => $invoiceId, 'number' => $invoice['number'].'-C-'.substr($creditId, 0, 10), 'owner_id' => $invoice['owner_id'], 'client_ids' => $invoice['client_ids'], 'currency' => $invoice['currency'], 'amount_minor' => (string) $amount, 'reason' => $data['reason'], 'issued_by' => $user->id, 'issued_at' => now()->toIso8601String(), 'business' => $invoice['snapshot']['business'], 'recipient' => $invoice['snapshot']['recipient']], $creditId);
            $status = $credited === (int) $invoice['total_minor'] ? 'credited' : ((int) $invoice['paid_minor'] + $credited >= (int) $invoice['total_minor'] ? 'paid' : 'part_paid');
            $this->store->put('invoices', $invoiceId, array_replace($invoice, ['credited_minor' => (string) $credited, 'status' => $status]), $invoice['version']);
            $this->audit->log($user->id, 'invoice.credit_issued', 'credit_notes', $creditId);

            return $credit;
        });
    }

    /** $approver is the person deciding a manual refund; they must not be the person who recorded the payment. */
    public function refundRecord(string $paymentId, mixed $amount, string $reference, string $key, ?string $actorId, ?CrmUser $approver = null): array
    {
        $amount = Money::minor($amount, allowZero: false);
        $refundId = hash('sha256', $paymentId.':'.$key);

        return $this->store->transaction(function () use ($paymentId, $amount, $reference, $refundId, $actorId, $approver) {
            $payment = $this->store->get('payments', $paymentId);
            abort_unless($payment !== null, 404);
            if ($previous = $this->store->get('refunds', $refundId)) {
                abort_unless($previous['amount_minor'] === (string) $amount, 409, 'Refund key used for a different amount.');

                return $previous;
            }
            if ($approver) {
                $this->approvals->ensureIndependent($approver, [$payment['confirmed_by'] ?? null], 'refund: you recorded the original payment', 'payments', $paymentId);
            }
            $refunded = (int) $payment['refunded_minor'] + $amount;
            abort_if($refunded > (int) $payment['amount_minor'], 409, 'Refund exceeds this payment.');
            $invoice = $this->store->get('invoices', $payment['invoice_id']);
            $refund = $this->store->create('refunds', ['payment_id' => $paymentId, 'invoice_id' => $invoice['id'], 'amount_minor' => (string) $amount, 'currency' => $payment['currency'], 'reference' => $reference, 'confirmed_by' => $actorId, 'confirmed_at' => now()->toIso8601String()], $refundId);
            $this->store->put('payments', $paymentId, array_replace($payment, ['refunded_minor' => (string) $refunded]), $payment['version']);
            $paid = (int) $invoice['paid_minor'] - $amount;
            $this->store->put('invoices', $invoice['id'], array_replace($invoice, ['paid_minor' => (string) $paid, 'refunded_minor' => (string) ((int) ($invoice['refunded_minor'] ?? '0') + $amount), 'status' => $paid + (int) ($invoice['credited_minor'] ?? '0') >= (int) $invoice['total_minor'] ? 'paid' : ($paid > 0 ? 'part_paid' : 'issued')]), $invoice['version']);
            $this->audit->log($actorId, 'payment.refunded', 'refunds', $refundId);

            return $refund;
        });
    }
}
