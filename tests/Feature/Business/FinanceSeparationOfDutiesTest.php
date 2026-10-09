<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Auth\CrmUser;
use App\Domain\Finance\EmployeeInputs;
use App\Domain\Finance\InvoiceService;
use App\Domain\Finance\PayrollService;

final class FinanceSeparationOfDutiesTest extends BusinessTestCase
{
    private function as(CrmUser $user): self
    {
        return $this->actingAs($user)->withSession(['auth.confirmed_at' => time()]);
    }

    private function allowSelfApproval(): void
    {
        $this->store->create('settings', ['allow_self_approval' => true], 'security');
    }

    public function test_employee_inputs_need_an_approver_other_than_the_employee_and_the_submitter(): void
    {
        $hr = $this->user('hr');
        $otherHr = $this->user('hr');
        $employee = $this->user('lawyer');
        $own = $this->as($hr)->postJson('/api/v1/employee-inputs/leave', ['from' => '2026-10-12', 'to' => '2026-10-13', 'kind' => 'annual', 'units' => 2])->assertCreated()->json('data');
        $this->postJson('/api/v1/employee-inputs/leave/'.$own['id'].'/decision', ['decision' => 'approved', 'notes' => 'Own leave'])->assertForbidden();
        $plan = $this->postJson('/api/v1/employee-inputs/compensation', ['employee_id' => $employee->id, 'effective_from' => '2026-10', 'currency' => 'USD', 'earnings' => [['label' => 'Base', 'amount_minor' => '500000']]])->assertCreated()->json('data');
        $this->postJson('/api/v1/employee-inputs/compensation/'.$plan['id'].'/decision', ['decision' => 'approved', 'notes' => 'Own submission'])->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'different person'));
        $this->assertSame('submitted', $this->store->get('employee_compensation', $plan['id'])['status']);
        $this->as($otherHr)->postJson('/api/v1/employee-inputs/compensation/'.$plan['id'].'/decision', ['decision' => 'approved', 'notes' => 'Checked'])->assertOk();
        $this->postJson('/api/v1/employee-inputs/leave/'.$own['id'].'/decision', ['decision' => 'approved', 'notes' => 'Checked'])->assertOk();
    }

    public function test_payroll_preparer_last_editor_and_included_employees_cannot_approve_or_release(): void
    {
        $preparer = $this->user('hr');
        $editor = $this->user('hr');
        $paidHr = $this->user('hr');
        $approver = $this->user('hr');
        $employee = $this->user('lawyer');
        $payroll = app(PayrollService::class);
        $input = ['period' => '2026-10', 'currency' => 'USD', 'employees' => [['employee_id' => $employee->id, 'name' => 'Lawyer', 'earnings' => [['label' => 'Salary', 'amount_minor' => '500000']]], ['employee_id' => $paidHr->id, 'name' => 'HR', 'earnings' => [['label' => 'Salary', 'amount_minor' => '400000']]]]];
        $run = $payroll->save($preparer, $input);
        $this->assertSame($preparer->id, $run['prepared_by']);
        $run = $payroll->save($editor, $input + ['version' => $run['version']], $run['id']);
        $this->assertSame([$preparer->id, $editor->id], [$run['prepared_by'], $run['updated_by']]);
        $payroll->transition($preparer, $run['id'], 'review');
        foreach ([$preparer, $editor, $paidHr] as $actor) {
            $this->as($actor)->postJson('/api/v1/payroll-runs/'.$run['id'].'/approve')->assertForbidden();
        }
        $this->assertSame('reviewed', $this->store->get('payroll_runs', $run['id'])['status']);
        $this->as($approver)->postJson('/api/v1/payroll-runs/'.$run['id'].'/approve')->assertOk();
        $this->as($paidHr)->postJson('/api/v1/payroll-runs/'.$run['id'].'/release')->assertForbidden();
        $this->assertCount(0, $this->store->query('payslips'));
        $this->as($preparer)->postJson('/api/v1/payroll-runs/'.$run['id'].'/release')->assertOk()->assertJsonPath('data.status', 'released');
    }

    public function test_credit_note_issuer_and_manual_refund_recorder_must_differ_from_the_earlier_actor(): void
    {
        $issuer = $this->user('accounts');
        $reviewer = $this->user('accounts');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($issuer, $invoices->save($issuer, $this->invoiceInput())['id']);
        $credit = ['amount_minor' => '2000', 'reason' => 'Goodwill', 'idempotency_key' => 'credit-key-001'];
        $this->as($issuer)->postJson('/api/v1/invoices/'.$invoice['id'].'/credits', $credit)->assertForbidden();
        $this->assertCount(0, $this->store->query('credit_notes'));
        $this->as($reviewer)->postJson('/api/v1/invoices/'.$invoice['id'].'/credits', $credit)->assertCreated();

        $payment = $this->as($reviewer)->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', ['amount_minor' => '5000', 'method' => 'cash', 'reference' => 'Cash 1', 'idempotency_key' => 'payment-key-001'])->assertCreated()->json('data');
        $refund = ['amount_minor' => '1000', 'reference' => 'Returned cash', 'idempotency_key' => 'refund-key-001'];
        $this->postJson('/api/v1/payments/'.$payment['id'].'/refund', $refund)->assertForbidden();
        $this->assertSame('5000', $this->store->get('invoices', $invoice['id'])['paid_minor']);
        $this->as($issuer)->postJson('/api/v1/payments/'.$payment['id'].'/refund', $refund)->assertOk();
        $this->assertSame('4000', $this->store->get('invoices', $invoice['id'])['paid_minor']);
    }

    public function test_owner_setting_allows_audited_self_approval_for_small_practices(): void
    {
        $this->allowSelfApproval();
        $employee = $this->user('lawyer');
        $inputs = app(EmployeeInputs::class);
        $plan = $inputs->save($this->owner, 'compensation', ['employee_id' => $employee->id, 'effective_from' => '2026-10', 'currency' => 'USD', 'earnings' => [['label' => 'Base', 'amount_minor' => '500000']]]);
        $this->assertSame('approved', $inputs->decide($this->owner, 'compensation', $plan['id'], ['decision' => 'approved', 'notes' => 'Sole practitioner'])['status']);
        $payroll = app(PayrollService::class);
        $run = $payroll->save($this->owner, ['period' => '2026-10', 'currency' => 'USD', 'employees' => [['employee_id' => $employee->id, 'name' => 'Lawyer', 'earnings' => [['label' => 'Salary', 'amount_minor' => '500000']]]]]);
        $payroll->transition($this->owner, $run['id'], 'review');
        $this->assertSame('approved', $payroll->transition($this->owner, $run['id'], 'approve')['status']);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($this->owner, $invoices->save($this->owner, $this->invoiceInput())['id']);
        $invoices->credit($this->owner, $invoice['id'], ['amount_minor' => '1000', 'reason' => 'Goodwill', 'idempotency_key' => 'credit-key-002']);
        $this->assertCount(3, $this->store->query('audit', ['action' => 'approval.self_approved']));
    }
}
