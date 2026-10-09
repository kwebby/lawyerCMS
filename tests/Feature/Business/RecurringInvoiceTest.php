<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Domain\Finance\InvoiceService;
use App\Domain\Finance\RecurringInvoiceService;

final class RecurringInvoiceTest extends BusinessTestCase
{
    public function test_schedule_approval_snapshots_template_and_retries_create_one_draft_only(): void
    {
        $service = app(RecurringInvoiceService::class);
        $template = app(InvoiceService::class)->save($this->owner, $this->invoiceInput());
        $schedule = $service->save($this->owner, ['invoice_id' => $template['id'], 'cadence' => 'monthly', 'next_run_at' => now()->subDay()->toISOString(), 'timezone' => 'UTC']);
        $this->assertSame(0, $service->queueDue());
        $schedule = $service->approve($this->owner, $schedule['id']);
        app(InvoiceService::class)->save($this->owner, array_replace($this->invoiceInput(), ['version' => $template['version'], 'items' => [['description' => 'Changed later', 'unit_minor' => '99999']]]), $template['id']);
        $this->assertSame(1, $service->queueDue());
        $service->queueDue();
        $this->assertCount(1, $this->store->query('jobs', ['type' => 'billing.recurring']));
        $invoice = $service->generate($schedule['id'], $schedule['next_run_at']);
        $this->assertSame('22000', $invoice['total_minor']);
        $this->assertSame('draft', $invoice['status']);
        $this->assertSame('internal', $invoice['visibility']);
        $this->assertArrayNotHasKey('number', $invoice);
        $this->assertSame($invoice['id'], $service->generate($schedule['id'], $schedule['next_run_at'])['id']);
        $this->assertSame(1, $this->store->get('recurring_invoices', $schedule['id'])['generation_count']);
        $this->assertCount(0, $this->store->query('payments'));
        $this->assertCount(0, $this->store->query('invoice_sequences'));
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'invoice.issued']));
    }

    public function test_month_end_anchor_is_preserved_and_end_date_completes_schedule(): void
    {
        $this->travelTo(now()->setDate(2028, 3, 31)->startOfDay());
        $template = app(InvoiceService::class)->save($this->owner, $this->invoiceInput());
        $service = app(RecurringInvoiceService::class);
        $schedule = $service->approve($this->owner, $service->save($this->owner, ['invoice_id' => $template['id'], 'cadence' => 'monthly', 'next_run_at' => '2028-01-31T00:00:00Z', 'end_at' => '2028-03-31T00:00:00Z', 'timezone' => 'UTC'])['id']);
        $service->generate($schedule['id'], $schedule['next_run_at']);
        $schedule = $this->store->get('recurring_invoices', $schedule['id']);
        $this->assertSame('2028-02-29T00:00:00.000000Z', $schedule['next_run_at']);
        $service->generate($schedule['id'], $schedule['next_run_at']);
        $schedule = $this->store->get('recurring_invoices', $schedule['id']);
        $this->assertSame('2028-03-31T00:00:00.000000Z', $schedule['next_run_at']);
        $service->generate($schedule['id'], $schedule['next_run_at']);
        $this->assertSame('completed', $this->store->get('recurring_invoices', $schedule['id'])['status']);
        $this->assertSame(0, $service->queueDue());
    }

    public function test_revoked_approver_or_closed_matter_pauses_instead_of_generating(): void
    {
        $template = app(InvoiceService::class)->save($this->owner, $this->invoiceInput());
        $service = app(RecurringInvoiceService::class);
        $schedule = $service->approve($this->owner, $service->save($this->owner, ['invoice_id' => $template['id'], 'cadence' => 'weekly', 'next_run_at' => now()->subHour()->toISOString()])['id']);
        $user = $this->store->get('users', $this->owner->id);
        $this->store->put('users', $user['id'], array_replace($user, ['status' => 'disabled']), $user['version']);
        $this->assertNull($service->generate($schedule['id'], $schedule['next_run_at']));
        $this->assertSame('paused', $this->store->get('recurring_invoices', $schedule['id'])['status']);
        $this->assertCount(1, $this->store->query('invoices'));
    }
}
