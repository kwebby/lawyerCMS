<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PayrollService
{
    public function __construct(private RecordStore $store, private Access $access, private Audit $audit, private Outbox $outbox) {}

    public function list($user): array
    {
        $this->access->authorize($user, 'payroll.read');

        return $this->access->filter($user, 'payroll.read', $this->store->each('payroll_runs'));
    }

    public function find($user, string $id, string $action = 'read'): array
    {
        $run = $this->store->get('payroll_runs', $id);
        abort_unless($run !== null, 404);
        $this->access->authorize($user, 'payroll.'.$action, $run);

        return $run;
    }

    public function save($user, array $input, ?string $id = null): array
    {
        $old = $id ? $this->find($user, $id, 'write') : null;
        $this->access->authorize($user, 'payroll.write', $old);
        abort_if($old && $old['status'] !== 'draft', 409, 'Reviewed salary runs are immutable. Create an adjustment run.');
        $data = Validator::make($input, ['version' => $id ? 'required|integer|min:1' : 'prohibited', 'period' => ['required', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/D'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'adjustment_of' => 'sometimes|nullable|string|max:100', 'notes' => 'sometimes|nullable|string|max:5000', 'employees' => 'required|array|min:1|max:100', 'employees.*' => 'array:employee_id,name,earnings,deductions,attendance_reference', 'employees.*.employee_id' => 'required|string|max:100|distinct', 'employees.*.name' => 'required|string|max:200', 'employees.*.attendance_reference' => 'sometimes|nullable|string|max:1000', 'employees.*.earnings' => 'required|array|min:1|max:30', 'employees.*.earnings.*' => 'array:label,amount_minor', 'employees.*.earnings.*.label' => 'required|string|max:150', 'employees.*.earnings.*.amount_minor' => 'required', 'employees.*.deductions' => 'sometimes|array|max:30', 'employees.*.deductions.*' => 'array:label,amount_minor', 'employees.*.deductions.*.label' => 'required|string|max:150', 'employees.*.deductions.*.amount_minor' => 'required'])->validate();
        $total = 0;
        foreach ($data['employees'] as &$employee) {
            $account = $this->store->get('users', $employee['employee_id']);
            abort_unless($account && count(array_diff($account['roles'] ?? [], ['client', 'prospect', 'external', 'collaborator'])) > 0, 422, 'Salary recipients must be staff accounts.');
            $gross = 0;
            $deductions = 0;
            foreach ($employee['earnings'] as &$earning) {
                $value = Money::minor($earning['amount_minor'], 'employees.earnings');
                $gross += $value;
                $earning['amount_minor'] = (string) $value;
            }
            unset($earning);
            foreach ($employee['deductions'] ?? [] as $deduction) {
                $deductions += Money::minor($deduction['amount_minor'], 'employees.deductions');
            }
            if ($deductions > $gross || $gross > Money::MAX_MINOR) {
                throw ValidationException::withMessages(['employees' => 'Deductions cannot exceed earnings, and totals must fit the supported money range.']);
            }
            $employee['gross_minor'] = (string) $gross;
            $employee['deductions_minor'] = (string) $deductions;
            $employee['net_minor'] = (string) ($gross - $deductions);
            $total += $gross - $deductions;
        }
        unset($employee);
        abort_if($total > Money::MAX_MINOR, 422, 'Salary run total exceeds the supported range.');
        if (! empty($data['adjustment_of'])) {
            $prior = $this->find($user, $data['adjustment_of']);
            abort_unless($prior['status'] === 'released', 422, 'Only released runs can receive an adjustment run.');
        }
        abort_if(strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 131072, 422, 'This financial record exceeds the portable 128 KiB limit. Split it into smaller documents.');
        $version = $data['version'] ?? null;
        unset($data['version']);
        $record = array_replace($old ?? ['owner_id' => $user->id, 'status' => 'draft', 'client_ids' => [], 'team_ids' => []], $data, ['total_minor' => (string) $total]);

        return $this->store->transaction(function () use ($user, $record, $version, $id) {
            if ($id) {
                abort_unless($this->store->get('payroll_runs', $id)['status'] === 'draft', 409, 'Salary run is no longer editable.');
            }
            $saved = $id ? $this->store->put('payroll_runs', $id, $record, $version) : $this->store->create('payroll_runs', $record);
            $this->audit->log($user->id, 'payroll.draft_saved', 'payroll_runs', $saved['id']);

            return $saved;
        });
    }

    public function transition($user, string $id, string $action): array
    {
        $this->find($user, $id, $action === 'review' ? 'write' : 'approve');
        abort_unless(in_array($action, ['review', 'approve', 'release'], true), 404);

        return $this->store->transaction(function () use ($user, $id, $action) {
            $run = $this->store->get('payroll_runs', $id);
            [$from, $to] = match ($action) {
                'review' => ['draft', 'reviewed'], 'approve' => ['reviewed', 'approved'], 'release' => ['approved', 'released']
            };
            if ($run['status'] === $to) {
                return $run;
            }
            abort_unless($run['status'] === $from, 409, 'Follow the draft, review, approval, release sequence.');
            $changes = ['status' => $to, $to.'_by' => $user->id, $to.'_at' => now()->toIso8601String()];
            if ($action === 'approve') {
                $business = $this->store->get('settings', 'business') ?? [];
                abort_unless(! empty($business['legal_name']) && ! empty($business['address']), 422, 'Complete employer business details before payroll approval.');
                $changes['business'] = array_intersect_key($business, array_flip(['legal_name', 'trading_name', 'address', 'tax_id', 'registration_id', 'email', 'phone', 'logo_data', 'signature']));
                $changes['design'] = $this->store->get('settings', 'invoice_design') ?? ['template' => 'classic', 'paper' => 'A4', 'accent' => '#174D3B', 'font' => 'dejavusans', 'margin_mm' => 15];
            }
            if ($action === 'release') {
                foreach ($run['employees'] as $employee) {
                    $slipId = hash('sha256', $id.':'.$employee['employee_id']);
                    if (! $this->store->get('payslips', $slipId)) {
                        $this->store->create('payslips', array_merge($employee, ['run_id' => $id, 'owner_id' => $employee['employee_id'], 'employee_id' => $employee['employee_id'], 'client_ids' => [], 'team_ids' => [], 'period' => $run['period'], 'currency' => $run['currency'], 'business' => $run['business'], 'design' => $run['design'], 'released_at' => now()->toIso8601String(), 'payment_status' => 'unpaid']), $slipId);
                        $this->outbox->enqueue('payslip.released', ['payslip_id' => $slipId, 'user_id' => $employee['employee_id']], 'payslip-'.$slipId);
                    }
                }
            }
            $saved = $this->store->put('payroll_runs', $id, array_replace($run, $changes), $run['version']);
            $this->audit->log($user->id, 'payroll.'.$to, 'payroll_runs', $id);

            return $saved;
        });
    }

    public function slips($user): array
    {
        return $this->access->cached(function () use ($user) {
            $slips = [];
            foreach ($this->store->each('payslips') as $slip) {
                if ($slip['employee_id'] === $user->id || $this->access->can($user, 'payroll.read', $slip)) {
                    $slips[] = $slip;
                }
            }

            return $slips;
        });
    }

    public function slip($user, string $id): array
    {
        $slip = $this->store->get('payslips', $id);
        abort_unless($slip !== null, 404);
        abort_unless($slip['employee_id'] === $user->id || $this->access->can($user, 'payroll.read', $slip), 403);

        return $slip;
    }

    public function confirmPayment($user, string $id, array $input): array
    {
        $slip = $this->slip($user, $id);
        $this->access->authorize($user, 'payroll.approve', $slip);
        $data = Validator::make($input, ['reference' => 'required|string|max:500', 'paid_at' => 'required|date|before_or_equal:now'])->validate();

        return $this->store->transaction(function () use ($id, $data, $user) {
            $slip = $this->store->get('payslips', $id);
            if ($slip['payment_status'] === 'paid') {
                abort_unless($slip['payment_reference'] === $data['reference'], 409, 'Payment is already confirmed with another reference.');

                return $slip;
            }
            $saved = $this->store->put('payslips', $id, array_replace($slip, ['payment_status' => 'paid', 'payment_reference' => $data['reference'], 'paid_at' => $data['paid_at'], 'payment_confirmed_by' => $user->id]), $slip['version']);
            $this->audit->log($user->id, 'payslip.payment_confirmed', 'payslips', $id);

            return $saved;
        });
    }
}
