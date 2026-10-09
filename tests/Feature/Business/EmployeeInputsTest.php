<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Domain\Finance\EmployeeInputs;

final class EmployeeInputsTest extends BusinessTestCase
{
    public function test_self_service_is_private_and_requires_hr_for_approval(): void
    {
        $lawyer = $this->user('lawyer');
        $other = $this->user('lawyer');
        $this->actingAs($lawyer);
        $input = ['from' => '2026-10-12', 'to' => '2026-10-13', 'kind' => 'annual', 'units' => 2];
        $leave = $this->postJson('/api/v1/employee-inputs/leave', $input)->assertCreated()->json('data');
        $this->postJson('/api/v1/employee-inputs/leave', $input + ['employee_id' => $other->id])->assertForbidden();
        $this->postJson('/api/v1/employee-inputs/leave/'.$leave['id'].'/decision', ['decision' => 'approved', 'notes' => 'Reviewed'])->assertForbidden();
        $this->actingAs($other)->getJson('/api/v1/employee-inputs/leave')->assertJsonCount(0, 'data');
        $this->actingAs($this->user('client'))->getJson('/api/v1/employee-inputs/leave')->assertUnprocessable();
    }

    public function test_approved_inputs_generate_an_idempotent_draft_and_freeze_source_snapshots(): void
    {
        $person = $this->user('lawyer');
        $hr = $this->user('hr');
        $service = app(EmployeeInputs::class);
        $comp = $service->save($this->owner, 'compensation', ['employee_id' => $person->id, 'effective_from' => '2026-10', 'currency' => 'USD', 'earnings' => [['label' => 'Base', 'amount_minor' => '500000']], 'deductions' => [['label' => 'Approved deduction', 'amount_minor' => '10000']]]);
        $service->decide($hr, 'compensation', $comp['id'], ['decision' => 'approved', 'notes' => 'Agreed compensation']);
        $att = $service->save($person, 'attendance', ['period' => '2026-10', 'scheduled_minutes' => 9600, 'worked_minutes' => 9600]);
        $service->decide($this->owner, 'attendance', $att['id'], ['decision' => 'approved', 'notes' => 'Records checked']);
        $request = ['period' => '2026-10', 'currency' => 'USD', 'employee_ids' => [$person->id], 'idempotency_key' => 'payroll-fixture-key'];
        $first = $service->generate($this->owner, $request);
        $again = $service->generate($this->owner, $request);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('draft', $first['status']);
        $this->assertSame('490000', $first['total_minor']);
        $this->assertSame($att['id'], $first['input_snapshots'][0]['attendance']['id']);
        $this->assertCount(0, $this->store->query('payslips'));
        $this->postJson('/api/v1/payroll-from-inputs', array_replace($request, ['period' => '2026-11']))->assertConflict();
    }

    public function test_compensation_overlap_and_invalid_leave_references_are_rejected(): void
    {
        $person = $this->user('lawyer');
        $hr = $this->user('hr');
        $service = app(EmployeeInputs::class);
        $data = ['employee_id' => $person->id, 'effective_from' => '2026-10', 'currency' => 'USD', 'earnings' => [['label' => 'Base', 'amount_minor' => '10000']]];
        $first = $service->save($this->owner, 'compensation', $data);
        // The owner submitted these plans, so another HR user approves them.
        $service->decide($hr, 'compensation', $first['id'], ['decision' => 'approved', 'notes' => 'Checked']);
        $next = $service->save($this->owner, 'compensation', $data);
        $this->actingAs($hr)->withSession(['auth.confirmed_at' => time()])->postJson('/api/v1/employee-inputs/compensation/'.$next['id'].'/decision', ['decision' => 'approved', 'notes' => 'Checked'])->assertUnprocessable();
        $att = $service->save($person, 'attendance', ['period' => '2026-10', 'scheduled_minutes' => 9600, 'worked_minutes' => 8000, 'approved_leave_ids' => ['not-approved']]);
        $this->postJson('/api/v1/employee-inputs/attendance/'.$att['id'].'/decision', ['decision' => 'approved', 'notes' => 'Checked'])->assertUnprocessable();
        $this->assertSame('submitted', $this->store->get('employee_attendance', $att['id'])['status']);
    }
}
