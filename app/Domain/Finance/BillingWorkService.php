<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Support\Facades\Validator;

final class BillingWorkService
{
    public function __construct(private RecordStore $store, private Access $access, private Audit $audit, private InvoiceService $invoices) {}

    private function collection(string $collection): void
    {
        abort_unless(in_array($collection, ['time_entries', 'expenses'], true), 404);
    }

    private function matter($user, string $id): array
    {
        $matter = $this->store->get('matters', $id);
        abort_unless($matter !== null, 422, 'Unknown matter.');
        $this->access->authorize($user, 'matters.read', $matter);

        return $matter;
    }

    public function find($user, string $collection, string $id, string $action = 'read'): array
    {
        $this->collection($collection);
        $record = $this->store->get($collection, $id);
        abort_unless($record !== null, 404);
        $this->access->authorize($user, $collection.'.'.$action, $record);
        $this->matter($user, $record['matter_id']);

        return $record;
    }

    public function list($user, string $collection, array $filters = []): array
    {
        $this->collection($collection);
        $this->access->authorize($user, $collection.'.read');
        $filters = array_intersect_key($filters, array_flip(['matter_id', 'status', 'owner_id']));

        return array_values(array_filter($this->store->query($collection, $filters, 500), function ($record) use ($user, $collection) {
            $matter = $this->store->get('matters', $record['matter_id']);

            return $matter !== null && $this->access->can($user, 'matters.read', $matter) && $this->access->can($user, $collection.'.read', $record);
        }));
    }

    public function save($user, string $collection, array $input, ?string $id = null): array
    {
        $this->collection($collection);
        $old = $id ? $this->find($user, $collection, $id, 'write') : null;
        $this->access->authorize($user, $collection.'.write', $old);
        abort_if($old && $old['status'] !== 'draft', 409, 'Only draft time and expenses can be edited. Reject submitted work for correction.');
        $rules = ['version' => $id ? 'required|integer|min:1' : 'prohibited', 'matter_id' => 'required|string|max:100', 'description' => 'required|string|max:2000', 'billing_description' => 'sometimes|string|max:1000', 'work_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'billable' => 'sometimes|boolean', 'tax_bps' => 'sometimes|integer|min:0|max:10000'];
        $rules = array_merge($rules, $collection === 'time_entries' ? ['minutes' => 'required|integer|min:1|max:1440', 'rate_minor' => 'required'] : ['amount_minor' => 'required', 'receipt_id' => 'sometimes|nullable|string|max:100']);
        $data = Validator::make($input, $rules)->validate();
        $matter = $this->matter($user, $data['matter_id']);
        abort_if(in_array($matter['status'] ?? '', ['closed', 'archived'], true), 409, 'Reopen the matter before entering new billable work.');
        if ($collection === 'time_entries') {
            $rate = Money::minor($data['rate_minor'], 'rate_minor');
            $data['rate_minor'] = (string) $rate;
            $data['amount_minor'] = (string) intdiv($rate * $data['minutes'] + 30, 60);
            Money::minor($data['amount_minor']);
        } else {
            $data['amount_minor'] = (string) Money::minor($data['amount_minor']);
            if (! empty($data['receipt_id'])) {
                $receipt = $this->store->get('documents', $data['receipt_id']);
                abort_unless($receipt !== null && ($receipt['status'] ?? '') === 'clean', 422, 'Receipt must be a scanned, clean document.');
                $this->access->authorize($user, 'documents.read', $receipt);
                abort_unless(($receipt['matter_id'] ?? $matter['id']) === $matter['id'], 422, 'Receipt belongs to another matter.');
            }
        }
        $version = $data['version'] ?? null;
        unset($data['version']);
        $record = array_replace($old ?? ['owner_id' => $user->id, 'billable' => true, 'status' => 'draft', 'tax_bps' => 0, 'billing_description' => $collection === 'time_entries' ? 'Professional services' : 'Approved expense'], $data, ['client_ids' => [], 'visibility' => 'internal', 'team_ids' => array_values(array_unique(array_merge([$matter['owner_id']], $matter['team_ids'] ?? []))), 'denied_user_ids' => $matter['denied_user_ids'] ?? [], 'confidentiality' => $matter['confidentiality'] ?? 'standard']);

        return $this->store->transaction(function () use ($user, $collection, $id, $record, $version) {
            if ($id) {
                abort_unless(($this->store->get($collection, $id)['status'] ?? '') === 'draft', 409, 'This work record has already entered review.');
            }
            $saved = $id ? $this->store->put($collection, $id, $record, $version) : $this->store->create($collection, $record);
            $this->audit->log($user->id, 'billing_work.saved', $collection, $saved['id']);

            return $saved;
        });
    }

    public function transition($user, string $collection, string $id, string $action, array $input = []): array
    {
        abort_unless(in_array($action, ['submit', 'approve', 'reject', 'archive'], true), 404);
        $this->find($user, $collection, $id, in_array($action, ['approve', 'reject'], true) ? 'approve' : 'write');
        if ($action === 'reject') {
            Validator::make($input, ['reason' => 'required|string|max:2000'])->validate();
        }

        return $this->store->transaction(function () use ($user, $collection, $id, $action, $input) {
            $record = $this->store->get($collection, $id);
            if ($action === 'archive') {
                abort_unless(($input['version'] ?? null) === $record['version'], 409, 'Work record changed. Reload before archiving.');
            }
            [$from, $to] = match ($action) {
                'submit' => ['draft', 'submitted'], 'approve' => ['submitted', 'approved'], 'reject' => ['submitted', 'draft'], 'archive' => ['draft', 'archived']
            };
            if ($record['status'] === $to && $action !== 'reject') {
                return $record;
            }
            abort_unless($record['status'] === $from, 409, 'Follow draft, submission, and approval before invoicing.');
            $changes = ['status' => $to, $action.'_by' => $user->id, $action.'_at' => now()->toISOString()];
            if ($action === 'reject') {
                $changes['review_notes'] = $input['reason'];
            }
            $saved = $this->store->put($collection, $id, array_replace($record, $changes), $record['version']);
            $this->audit->log($user->id, 'billing_work.'.$action, $collection, $id);

            return $saved;
        });
    }

    public function draftInvoice($user, array $input): array
    {
        $data = Validator::make($input, ['matter_id' => 'required|string|max:100', 'time_entry_ids' => 'sometimes|array|max:50', 'time_entry_ids.*' => 'string|max:100|distinct', 'expense_ids' => 'sometimes|array|max:50', 'expense_ids.*' => 'string|max:100|distinct', 'recipient' => 'required|array:name,email,address,tax_id', 'recipient.name' => 'required|string|max:250', 'recipient.email' => 'sometimes|nullable|email|max:254', 'recipient.address' => 'sometimes|nullable|string|max:3000', 'recipient.tax_id' => 'sometimes|nullable|string|max:150', 'client_ids' => 'sometimes|array|max:50', 'client_ids.*' => 'string|max:100', 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'idempotency_key' => 'required|string|min:8|max:120'])->validate();
        $this->matter($user, $data['matter_id']);
        $this->access->authorize($user, 'invoices.write');
        abort_unless(count($data['time_entry_ids'] ?? []) + count($data['expense_ids'] ?? []) > 0, 422, 'Select approved time or expenses.');
        $data['time_entry_ids'] ??= [];
        $data['expense_ids'] ??= [];
        sort($data['time_entry_ids']);
        sort($data['expense_ids']);
        $batchId = hash('sha256', $data['matter_id'].':'.$data['idempotency_key']);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $this->store->transaction(function () use ($user, $data, $batchId, $hash) {
            // Serialize conversion on the matter before checking keys to handle PostgreSQL read-committed races.
            $this->matter($user, $data['matter_id']);
            if ($batch = $this->store->get('billing_batches', $batchId)) {
                abort_unless(hash_equals($batch['request_hash'], $hash), 409, 'Billing key was used for different work or recipient details.');

                return $this->invoices->find($user, $batch['invoice_id']);
            }
            $sources = [];
            $items = [];
            foreach (['time_entries' => 'time_entry_ids', 'expenses' => 'expense_ids'] as $collection => $key) {
                foreach ($data[$key] as $id) {
                    $record = $this->find($user, $collection, $id);
                    abort_unless($record['matter_id'] === $data['matter_id'] && $record['status'] === 'approved' && $record['billable'] && $record['currency'] === $data['currency'], 422, 'Every selected record must be approved, billable, and use the same matter and currency.');
                    $items[] = ['description' => $record['billing_description'], 'quantity' => '1', 'unit_minor' => $record['amount_minor'], 'tax_bps' => $record['tax_bps']];
                    $sources[] = ['collection' => $collection, 'record' => $record];
                }
            }
            $invoice = $this->invoices->save($user, ['matter_id' => $data['matter_id'], 'recipient' => $data['recipient'], 'client_ids' => $data['client_ids'] ?? [], 'currency' => $data['currency'], 'items' => $items, 'fee_type' => count($data['expense_ids']) === count($items) ? 'expense' : 'hourly']);
            foreach ($sources as $source) {
                $this->store->put($source['collection'], $source['record']['id'], array_replace($source['record'], ['status' => 'invoiced', 'invoice_id' => $invoice['id'], 'invoiced_at' => now()->toISOString()]), $source['record']['version']);
            }
            $this->store->create('billing_batches', ['matter_id' => $data['matter_id'], 'invoice_id' => $invoice['id'], 'request_hash' => $hash, 'time_entry_ids' => $data['time_entry_ids'], 'expense_ids' => $data['expense_ids'], 'owner_id' => $user->id], $batchId);
            $this->audit->log($user->id, 'billing_work.draft_created', 'invoices', $invoice['id']);

            return $invoice;
        });
    }
}
