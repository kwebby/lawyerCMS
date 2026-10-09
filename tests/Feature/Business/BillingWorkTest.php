<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Domain\Finance\BillingWorkService;

final class BillingWorkTest extends BusinessTestCase
{
    private function matter(): array
    {
        return $this->store->create('matters', ['title' => 'Billing matter', 'owner_id' => $this->owner->id, 'status' => 'active', 'team_ids' => [], 'client_ids' => []]);
    }

    private function timeInput(string $id): array
    {
        return ['matter_id' => $id, 'description' => 'Sensitive litigation strategy; never publish', 'billing_description' => 'Professional services', 'work_date' => now()->toDateString(), 'minutes' => 61, 'rate_minor' => '10001', 'currency' => 'USD'];
    }

    public function test_time_and_expenses_require_review_then_convert_once_without_internal_notes(): void
    {
        $matter = $this->matter();
        $time = $this->postJson('/api/v1/time-entries', $this->timeInput($matter['id']))->assertCreated()->json('data');
        $this->assertSame('10168', $time['amount_minor']);
        $expense = $this->postJson('/api/v1/expenses', ['matter_id' => $matter['id'], 'description' => 'Private meeting travel route', 'billing_description' => 'Approved travel expense', 'work_date' => now()->toDateString(), 'currency' => 'USD', 'amount_minor' => '1000', 'tax_bps' => 1000])->assertCreated()->json('data');
        $input = ['matter_id' => $matter['id'], 'time_entry_ids' => [$time['id']], 'expense_ids' => [$expense['id']], 'currency' => 'USD', 'recipient' => ['name' => 'Billing client'], 'idempotency_key' => 'billing-batch-001'];
        $this->postJson('/api/v1/billing/draft-invoice', $input)->assertUnprocessable();
        foreach (['time-entries' => $time, 'expenses' => $expense] as $kind => $record) {
            $this->postJson('/api/v1/'.$kind.'/'.$record['id'].'/approve')->assertConflict();
            $this->postJson('/api/v1/'.$kind.'/'.$record['id'].'/submit')->assertOk();
            $this->postJson('/api/v1/'.$kind.'/'.$record['id'].'/approve')->assertOk();
        }
        $invoice = $this->postJson('/api/v1/billing/draft-invoice', $input)->assertCreated()->json('data');
        $this->assertSame('11268', $invoice['total_minor']);
        $this->assertSame('draft', $invoice['status']);
        $this->assertSame('internal', $invoice['visibility']);
        $this->assertStringNotContainsString('Sensitive litigation', json_encode($invoice));
        $this->assertStringNotContainsString('Private meeting', json_encode($invoice));
        $this->postJson('/api/v1/billing/draft-invoice', $input)->assertCreated()->assertJsonPath('data.id', $invoice['id']);
        $this->postJson('/api/v1/billing/draft-invoice', array_replace($input, ['idempotency_key' => 'another-batch-001']))->assertUnprocessable();
        $this->assertCount(1, $this->store->query('invoices'));
        $this->assertSame('invoiced', $this->store->get('time_entries', $time['id'])['status']);
        $this->assertCount(0, $this->store->query('payments'));
    }

    public function test_approved_work_is_immutable_clients_cannot_read_it_and_wrong_matter_is_rejected(): void
    {
        $client = $this->user('client');
        $matter = $this->matter();
        $this->store->put('matters', $matter['id'], array_replace($matter, ['client_ids' => [$client->id]]), $matter['version']);
        $service = app(BillingWorkService::class);
        $time = $service->save($this->owner, 'time_entries', $this->timeInput($matter['id']));
        $service->transition($this->owner, 'time_entries', $time['id'], 'submit');
        $approved = $service->transition($this->owner, 'time_entries', $time['id'], 'approve');
        $this->patchJson('/api/v1/time-entries/'.$time['id'], array_merge($this->timeInput($matter['id']), ['version' => $approved['version']]))->assertConflict();
        $this->actingAs($client)->getJson('/api/v1/time-entries/'.$time['id'])->assertForbidden();
        $this->getJson('/api/v1/time-entries')->assertForbidden();
        $this->actingAs($this->owner)->withSession(['auth.confirmed_at' => time()]);
        $other = $this->matter();
        $this->postJson('/api/v1/billing/draft-invoice', ['matter_id' => $other['id'], 'time_entry_ids' => [$time['id']], 'currency' => 'USD', 'recipient' => ['name' => 'Client'], 'idempotency_key' => 'wrong-matter-001'])->assertUnprocessable();
        $this->assertSame('approved', $this->store->get('time_entries', $time['id'])['status']);
    }

    public function test_rejection_returns_to_draft_and_stale_edits_are_rejected(): void
    {
        $matter = $this->matter();
        $time = $this->postJson('/api/v1/time-entries', $this->timeInput($matter['id']))->assertCreated()->json('data');
        $this->postJson('/api/v1/time-entries/'.$time['id'].'/submit')->assertOk();
        $this->postJson('/api/v1/time-entries/'.$time['id'].'/reject', ['reason' => 'Please correct minutes'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->patchJson('/api/v1/time-entries/'.$time['id'], array_merge($this->timeInput($matter['id']), ['version' => $time['version']]))->assertConflict();
        $this->postJson('/api/v1/expenses', ['matter_id' => $matter['id'], 'description' => 'Unscanned receipt', 'work_date' => now()->toDateString(), 'currency' => 'USD', 'amount_minor' => '1000', 'receipt_id' => 'unknown-file'])->assertUnprocessable();
    }
}
