<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class EmployeeInputs
{
    public const COLLECTIONS = ['leave' => 'employee_leave', 'attendance' => 'employee_attendance', 'compensation' => 'employee_compensation'];

    public function __construct(private RecordStore $store, private Access $access, private Audit $audit, private Outbox $outbox, private PayrollService $payroll) {}

    private function staff(string $id): array
    {
        $person = $this->store->get('users', $id);
        abort_unless($person && ($person['status'] ?? 'active') === 'active' && ! array_intersect($person['roles'], ['client', 'prospect', 'collaborator', 'external']), 422, 'Select an active employee.');

        return $person;
    }

    private function collection(string $kind): string
    {
        abort_unless(isset(self::COLLECTIONS[$kind]), 404);

        return self::COLLECTIONS[$kind];
    }

    public function list($user, string $kind): array
    {
        $collection = $this->collection($kind);
        $this->staff($user->id);
        $manage = $this->access->can($user, 'employees.read');

        return iterator_to_array($this->store->each($collection, $manage ? [] : ['employee_id' => $user->id]), false);
    }

    public function save($user, string $kind, array $input): array
    {
        $collection = $this->collection($kind);
        $this->staff($user->id);
        $employeeId = $input['employee_id'] ?? $user->id;
        $person = $this->staff($employeeId);
        if ($employeeId !== $user->id || $kind === 'compensation') {
            $this->access->authorize($user, 'employees.write');
        }
        $month = ['required', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/D'];
        $rules = match ($kind) {
            'leave' => ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'kind' => ['required', Rule::in(['annual', 'sick', 'unpaid', 'other'])], 'units' => 'required|numeric|min:0.5|max:366', 'coverage_notes' => 'nullable|string|max:2000'],
            'attendance' => ['period' => $month, 'scheduled_minutes' => 'required|integer|min:0|max:44640', 'worked_minutes' => 'required|integer|min:0|max:44640', 'approved_leave_ids' => 'sometimes|array|max:50', 'approved_leave_ids.*' => 'required|string|distinct', 'notes' => 'nullable|string|max:2000'],
            'compensation' => ['effective_from' => $month, 'effective_to' => ['nullable', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/D'], 'currency' => ['required', Currency::rule()], 'earnings' => 'required|array|min:1|max:30', 'earnings.*' => 'array:label,amount_minor', 'earnings.*.label' => 'required|string|max:150', 'earnings.*.amount_minor' => 'required', 'deductions' => 'sometimes|array|max:30', 'deductions.*' => 'array:label,amount_minor', 'deductions.*.label' => 'required|string|max:150', 'deductions.*.amount_minor' => 'required'],
            default => throw new \InvalidArgumentException('Unknown employee input kind.'),
        };
        $data = Validator::make($input, $rules)->validate();
        if ($kind === 'compensation') {
            abort_if(! empty($data['effective_to']) && $data['effective_to'] < $data['effective_from'], 422, 'The end month precedes the start month.');
            foreach (['earnings', 'deductions'] as $field) {
                foreach ($data[$field] ?? [] as $i => $line) {
                    $data[$field][$i]['amount_minor'] = (string) Money::minor($line['amount_minor'], $field);
                }
            }
        }

        return $this->store->transaction(function () use ($user, $collection, $kind, $data, $employeeId, $person) {
            $saved = $this->store->create($collection, $data + ['employee_id' => $employeeId, 'employee_name' => $person['name'], 'owner_id' => $employeeId, 'status' => 'submitted', 'created_by' => $user->id]);
            $this->audit->log($user->id, 'employee.'.$kind.'_submitted', $collection, $saved['id']);
            $this->outbox->enqueue('employee.review', ['employee_id' => $employeeId], $saved['id']);

            return $saved;
        });
    }

    public function decide($user, string $kind, string $id, array $input): array
    {
        $collection = $this->collection($kind);
        $this->access->authorize($user, 'employees.approve');
        $data = Validator::make($input, ['decision' => ['required', Rule::in(['approved', 'rejected'])], 'notes' => 'required|string|max:2000'])->validate();

        return $this->store->transaction(function () use ($user, $kind, $collection, $id, $data) {
            $record = $this->store->get($collection, $id);
            abort_unless($record !== null, 404);
            $person = $this->staff($record['employee_id']); // Serializes approvals for each employee on SQL and participates in Firestore transaction reads.
            abort_unless($record['status'] === 'submitted', 409, 'This input has already been decided.');
            if ($data['decision'] === 'approved') {
                if ($kind === 'attendance') {
                    foreach ($record['approved_leave_ids'] ?? [] as $leaveId) {
                        $leave = $this->store->get('employee_leave', $leaveId);
                        abort_unless($leave && $leave['employee_id'] === $person['id'] && $leave['status'] === 'approved' && substr($leave['from'], 0, 7) <= $record['period'] && substr($leave['to'], 0, 7) >= $record['period'], 422, 'Attendance may reference only this employee’s approved leave overlapping the pay month.');
                    }
                }
                foreach ($this->store->query($collection, ['employee_id' => $person['id']], 500) as $other) {
                    if ($other['id'] === $id || $other['status'] !== 'approved') {
                        continue;
                    }
                    $overlap = match ($kind) {
                        'compensation' => $other['effective_from'] <= ($record['effective_to'] ?? '9999-12') && ($other['effective_to'] ?? '9999-12') >= $record['effective_from'],'attendance' => $other['period'] === $record['period'],'leave' => $other['from'] <= $record['to'] && $other['to'] >= $record['from'],default => throw new \InvalidArgumentException('Unknown employee input kind.')
                    };
                    abort_if($overlap, 422, 'An approved input already covers this employee and period.');
                }
                // A version change locks the employee approval aggregate on all database adapters.
                $this->store->put('users', $person['id'], array_replace($person, ['employee_input_revision' => ($person['employee_input_revision'] ?? 0) + 1]), $person['version']);
            }
            $saved = $this->store->put($collection, $id, array_replace($record, ['status' => $data['decision'], 'review_notes' => $data['notes'], 'reviewed_by' => $user->id, 'reviewed_at' => now()->toISOString()]), $record['version']);
            $this->audit->log($user->id, 'employee.'.$kind.'_'.$data['decision'], $collection, $id);
            $this->outbox->enqueue('employee.decision', ['employee_id' => $person['id']], $id.':'.$data['decision']);

            return $saved;
        });
    }

    public function generate($user, array $input): array
    {
        $this->access->authorize($user, 'payroll.write');
        $this->access->authorize($user, 'employees.read');
        $data = Validator::make($input, ['period' => ['required', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/D'], 'currency' => ['required', Currency::rule()], 'employee_ids' => 'required|array|min:1|max:100', 'employee_ids.*' => 'required|string|distinct', 'idempotency_key' => 'required|string|min:16|max:128'])->validate();
        sort($data['employee_ids']);
        $digest = hash('sha256', json_encode([$data['period'], $data['currency'], $data['employee_ids']], JSON_THROW_ON_ERROR));

        return $this->store->transaction(function () use ($user, $data, $digest) {
            $key = hash('sha256', $user->id.':'.$data['idempotency_key']);
            $existing = $this->store->get('payroll_generations', $key);
            if ($existing) {
                abort_unless($existing['digest'] === $digest, 409, 'This request key was already used for different inputs.');

                return $this->payroll->find($user, $existing['run_id']);
            }
            $employees = [];
            $snapshots = [];
            foreach ($data['employee_ids'] as $id) {
                $person = $this->staff($id);
                $plans = array_values(array_filter($this->store->query('employee_compensation', ['employee_id' => $id], 500), fn ($r) => $r['status'] === 'approved' && $r['currency'] === $data['currency'] && $r['effective_from'] <= $data['period'] && ($r['effective_to'] ?? '9999-12') >= $data['period']));
                $attendance = array_values(array_filter($this->store->query('employee_attendance', ['employee_id' => $id], 500), fn ($r) => $r['status'] === 'approved' && $r['period'] === $data['period']));
                abort_unless(count($plans) === 1 && count($attendance) === 1, 422, 'Every employee needs one approved compensation plan and attendance record for this month.');
                $plan = $plans[0];
                $att = $attendance[0];
                $employees[] = ['employee_id' => $id, 'name' => $person['name'], 'earnings' => $plan['earnings'], 'deductions' => $plan['deductions'] ?? [], 'attendance_reference' => $att['id']];
                $snapshots[] = ['employee_id' => $id, 'compensation' => $plan, 'attendance' => $att];
            }
            $run = $this->payroll->save($user, ['period' => $data['period'], 'currency' => $data['currency'], 'employees' => $employees, 'notes' => 'Prepared from approved compensation and attendance. Review all adjustments before release. No statutory or automatic leave deductions were calculated.']);
            abort_if(strlen(json_encode($snapshots, JSON_THROW_ON_ERROR)) > 65536, 422, 'Split this payroll into smaller employee groups.');
            $run = $this->store->put('payroll_runs', $run['id'], array_replace($run, ['input_snapshots' => $snapshots]), $run['version']);
            $this->store->create('payroll_generations', ['run_id' => $run['id'], 'digest' => $digest], $key);

            return $run;
        });
    }
}
