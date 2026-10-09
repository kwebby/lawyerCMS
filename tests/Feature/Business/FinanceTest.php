<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Domain\Finance\FinancialDocuments;
use App\Domain\Finance\InvoiceService;
use App\Domain\Finance\PayrollService;
use App\Support\JobRunner;
use App\Support\PrivateFiles;

final class FinanceTest extends BusinessTestCase
{
    public function test_issued_invoice_freezes_business_and_line_items_and_reuses_number(): void
    {
        $invoices = app(InvoiceService::class);
        $draft = $invoices->save($this->owner, $this->invoiceInput());
        $issued = $invoices->issue($this->owner, $draft['id']);
        $this->assertSame('22000', $issued['total_minor']);
        $this->assertSame($issued['number'], $invoices->issue($this->owner, $draft['id'])['number']);
        $oldBusiness = $this->store->get('settings', 'business');
        $this->store->put('settings', 'business', array_replace($oldBusiness, ['legal_name' => 'Different business']), $oldBusiness['version']);
        $this->assertSame('Example Legal LLP', $invoices->find($this->owner, $draft['id'])['snapshot']['business']['legal_name']);
        $this->patchJson('/api/v1/invoices/'.$draft['id'], array_merge($this->invoiceInput(), ['version' => $issued['version']]))->assertConflict();
        $second = $invoices->issue($this->owner, $invoices->save($this->owner, $this->invoiceInput())['id']);
        $this->assertNotSame($issued['number'], $second['number']);
        $this->assertStringEndsWith('000002', $second['number']);
    }

    public function test_manual_payment_exactly_once_refund_and_credit_keep_original_snapshot(): void
    {
        $service = app(InvoiceService::class);
        $invoice = $service->issue($this->owner, $service->save($this->owner, $this->invoiceInput())['id']);
        $input = ['amount_minor' => '10000', 'method' => 'bank_transfer', 'reference' => 'BANK-123', 'idempotency_key' => 'payment-key-123'];
        $payment = $service->payment($this->owner, $invoice['id'], $input);
        $this->assertSame($payment['id'], $service->payment($this->owner, $invoice['id'], $input)['id']);
        $this->assertSame('10000', $service->find($this->owner, $invoice['id'])['paid_minor']);
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', array_replace($input, ['amount_minor' => '10001']))->assertConflict();
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', array_replace($input, ['idempotency_key' => 'another-key-123', 'amount_minor' => '999999']))->assertConflict();
        $service->refundRecord($payment['id'], '4000', 'REF-123', 'refund-123', $this->owner->id);
        $service->refundRecord($payment['id'], '4000', 'REF-123', 'refund-123', $this->owner->id);
        $this->assertSame('6000', $service->find($this->owner, $invoice['id'])['paid_minor']);
        $credit = $service->credit($this->owner, $invoice['id'], ['amount_minor' => '16000', 'reason' => 'Scope reduction', 'idempotency_key' => 'credit-123']);
        $this->assertSame('16000', $credit['amount_minor']);
        $updated = $service->find($this->owner, $invoice['id']);
        $this->assertSame('paid', $updated['status']);
        $this->assertEquals($invoice['snapshot'], $updated['snapshot']);
    }

    public function test_private_invoice_pdf_is_cached_as_encrypted_file_and_client_access_is_explicit(): void
    {
        $client = $this->user('client');
        $other = $this->user('client');
        $service = app(InvoiceService::class);
        $invoice = $service->issue($this->owner, $service->save($this->owner, $this->invoiceInput(['client_ids' => [$client->id]]))['id']);
        $pdf = app(FinancialDocuments::class)->invoice($invoice);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $stored = $this->store->get('invoices', $invoice['id']);
        $encrypted = file_get_contents(config('crm.private_path').'/'.$stored['pdf_path']);
        $this->assertStringNotContainsString('%PDF-', $encrypted);
        $this->assertSame($pdf, app(FinancialDocuments::class)->invoice($stored));
        $this->actingAs($other)->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertForbidden();
        $this->actingAs($client)->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_payroll_approval_release_and_self_only_payslips(): void
    {
        $employee = $this->user('lawyer');
        $other = $this->user('lawyer');
        $service = app(PayrollService::class);
        $run = $service->save($this->owner, ['period' => '2026-10', 'currency' => 'USD', 'employees' => [['employee_id' => $employee->id, 'name' => 'Lawyer name', 'earnings' => [['label' => 'Salary', 'amount_minor' => '500000']], 'deductions' => [['label' => 'Leave deduction', 'amount_minor' => '25000']]]]]);
        $this->postJson('/api/v1/payroll-runs/'.$run['id'].'/release')->assertConflict();
        $service->transition($this->owner, $run['id'], 'review');
        $service->transition($this->owner, $run['id'], 'approve');
        $service->transition($this->owner, $run['id'], 'release');
        $service->transition($this->owner, $run['id'], 'release');
        $slips = $service->slips($employee);
        $this->assertCount(1, $slips);
        $this->assertSame('475000', $slips[0]['net_minor']);
        $this->assertSame('unpaid', $slips[0]['payment_status']);
        $this->assertSame([], $service->slips($other));
        $this->actingAs($other)->get('/api/v1/payslips/'.$slips[0]['id'].'/pdf')->assertForbidden();
        $this->actingAs($employee)->get('/api/v1/payslips/'.$slips[0]['id'].'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->owner)->withSession(['auth.confirmed_at' => time()])->postJson('/api/v1/payslips/'.$slips[0]['id'].'/payment', ['reference' => 'Bank batch A', 'paid_at' => now()->subMinute()->toISOString()])->assertOk()->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_queued_invoice_and_payslip_jobs_recover_missing_pdf_without_duplicate_artifacts(): void
    {
        $invoice = app(InvoiceService::class)->issue($this->owner, app(InvoiceService::class)->save($this->owner, $this->invoiceInput())['id']);
        $employee = $this->user('lawyer');
        $payroll = app(PayrollService::class);
        $run = $payroll->save($this->owner, ['period' => '2026-10', 'currency' => 'USD', 'employees' => [['employee_id' => $employee->id, 'name' => 'Employee', 'earnings' => [['label' => 'Salary', 'amount_minor' => '500000']]]]]);
        $payroll->transition($this->owner, $run['id'], 'review');
        $payroll->transition($this->owner, $run['id'], 'approve');
        $payroll->transition($this->owner, $run['id'], 'release');
        $slip = $payroll->slips($employee)[0];
        $this->assertArrayNotHasKey('pdf_path', $invoice);
        $this->assertArrayNotHasKey('pdf_path', $slip);
        $runner = app(JobRunner::class);
        $invoiceJob = $this->store->query('jobs', ['type' => 'invoice.issued'])[0];
        $slipJob = $this->store->query('jobs', ['type' => 'payslip.released'])[0];
        $runner->handle($invoiceJob);
        $runner->handle($slipJob);
        $invoicePath = $this->store->get('invoices', $invoice['id'])['pdf_path'];
        $slipPath = $this->store->get('payslips', $slip['id'])['pdf_path'];
        $notifications = count($this->store->query('notifications'));
        $runner->handle($invoiceJob);
        $runner->handle($slipJob);
        $this->assertSame($invoicePath, $this->store->get('invoices', $invoice['id'])['pdf_path']);
        $this->assertSame($slipPath, $this->store->get('payslips', $slip['id'])['pdf_path']);
        $this->assertCount($notifications, $this->store->query('notifications'));
        $this->assertStringStartsWith('%PDF-', app(PrivateFiles::class)->read($invoicePath));
        $this->assertStringStartsWith('%PDF-', app(PrivateFiles::class)->read($slipPath));
    }

    public function test_financial_actions_require_recent_authentication_and_invalid_money_is_rejected(): void
    {
        $this->postJson('/api/v1/invoices', $this->invoiceInput(['items' => [['description' => 'Invalid', 'unit_minor' => '1.99']]]))->assertUnprocessable();
        $this->postJson('/api/v1/invoices', $this->invoiceInput(['discount_minor' => '999999']))->assertUnprocessable();
        $invoice = app(InvoiceService::class)->save($this->owner, $this->invoiceInput());
        $this->withSession(['auth.confirmed_at' => 0])->postJson('/api/v1/invoices/'.$invoice['id'].'/issue')->assertStatus(423);
        $this->assertSame('draft', $this->store->get('invoices', $invoice['id'])['status']);
    }

    public function test_unknown_or_withdrawn_currency_codes_are_rejected_for_new_financial_records(): void
    {
        $employee = $this->user('lawyer');
        foreach (['XYZ', 'HRK', 'usd'] as $currency) {
            $this->postJson('/api/v1/invoices', $this->invoiceInput(['currency' => $currency]))->assertUnprocessable()->assertJsonValidationErrors('currency');
            $this->postJson('/api/v1/payroll-runs', ['period' => '2026-10', 'currency' => $currency, 'employees' => [['employee_id' => $employee->id, 'name' => 'Employee', 'earnings' => [['label' => 'Salary', 'amount_minor' => '500000']]]]])->assertUnprocessable()->assertJsonValidationErrors('currency');
            $this->postJson('/api/v1/employee-inputs/compensation', ['employee_id' => $employee->id, 'effective_from' => '2026-10', 'currency' => $currency, 'earnings' => [['label' => 'Base', 'amount_minor' => '500000']]])->assertUnprocessable()->assertJsonValidationErrors('currency');
        }
        $this->postJson('/api/v1/invoices', $this->invoiceInput(['currency' => 'RSD']))->assertCreated();
        $this->assertCount(1, $this->store->query('invoices'));
    }

    public function test_letterhead_logo_requires_clearance_and_is_frozen_with_selected_template(): void
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = app(PrivateFiles::class)->write($bytes, 'documents');
        $logo = $this->store->create('documents', ['title' => 'Firm logo', 'mime' => 'image/png', 'path' => $path, 'sha256' => hash('sha256', $bytes), 'status' => 'quarantined', 'owner_id' => $this->owner->id]);
        $settings = ['section' => 'business', 'data' => ['legal_name' => 'Brand One', 'address' => 'Practice address', 'currency' => 'USD', 'logo_file_id' => $logo['id'], 'signature' => 'Authorized Partner']];
        $this->patchJson('/api/v1/settings', $settings)->assertUnprocessable();
        $this->store->put('documents', $logo['id'], array_replace($logo, ['status' => 'clean']), $logo['version']);
        $this->patchJson('/api/v1/settings', $settings)->assertOk();
        $invoice = app(InvoiceService::class)->save($this->owner, $this->invoiceInput(['template' => 'compact']));
        $issued = app(InvoiceService::class)->issue($this->owner, $invoice['id']);
        $this->assertSame('compact', $issued['snapshot']['design']['template']);
        $this->assertNotEmpty($issued['snapshot']['business']['logo_data']);
        $pdf = app(FinancialDocuments::class)->invoice($issued);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->patchJson('/api/v1/settings', ['section' => 'business', 'data' => ['legal_name' => 'New Brand', 'currency' => 'USD', 'logo_file_id' => null]])->assertOk();
        $fresh = $this->store->get('invoices', $invoice['id']);
        $this->assertSame('Brand One', $fresh['snapshot']['business']['legal_name']);
        $this->assertSame($pdf, app(FinancialDocuments::class)->invoice($fresh));
    }
}
