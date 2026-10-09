<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Finance;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class RecurringInvoiceService
{
    public function __construct(private RecordStore $store, private Access $access, private Audit $audit, private Outbox $outbox, private InvoiceService $invoices) {}

    public function find($user, string $id, string $action = 'read'): array
    {
        $record = $this->store->get('recurring_invoices', $id);
        abort_unless($record !== null, 404);
        $this->access->authorize($user, 'recurring_invoices.'.$action, $record);
        if (! empty($record['matter_id'])) {
            $matter = $this->store->get('matters', $record['matter_id']);
            abort_unless($matter !== null, 403);
            $this->access->authorize($user, 'matters.read', $matter);
        }

        return $record;
    }

    public function list($user): array
    {
        $this->access->authorize($user, 'recurring_invoices.read');

        return array_values(array_filter($this->store->query('recurring_invoices', [], 500), function ($record) use ($user) {
            if (! $this->access->can($user, 'recurring_invoices.read', $record)) {
                return false;
            }
            if (empty($record['matter_id'])) {
                return true;
            }
            $matter = $this->store->get('matters', $record['matter_id']);

            return $matter !== null && $this->access->can($user, 'matters.read', $matter);
        }));
    }

    public function save($user, array $input, ?string $id = null): array
    {
        $old = $id ? $this->find($user, $id, 'write') : null;
        $this->access->authorize($user, 'recurring_invoices.write', $old);
        abort_if($old && ! in_array($old['status'], ['draft', 'paused'], true), 409, 'Pause an active schedule before changing it.');
        $data = Validator::make($input, ['version' => $id ? 'required|integer|min:1' : 'prohibited', 'invoice_id' => 'required|string|max:100', 'cadence' => ['required', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])], 'next_run_at' => 'required|date', 'end_at' => 'sometimes|nullable|date|after_or_equal:next_run_at', 'timezone' => 'sometimes|timezone'])->validate();
        $invoice = $this->invoices->find($user, $data['invoice_id'], 'write');
        $this->authorizeTemplateMatter($user, $invoice);
        $timezone = $data['timezone'] ?? config('app.timezone', 'UTC');
        $anchor = Carbon::parse($data['next_run_at'])->setTimezone($timezone);
        $version = $data['version'] ?? null;
        unset($data['version']);
        $data = array_replace($data, ['timezone' => $timezone, 'next_run_at' => $anchor->copy()->utc()->toISOString(), 'end_at' => ! empty($data['end_at']) ? Carbon::parse($data['end_at'])->utc()->toISOString() : null, 'anchor_day' => $anchor->day, 'anchor_time' => $anchor->format('H:i:s'), 'status' => 'draft', 'owner_id' => $old['owner_id'] ?? $user->id, 'client_ids' => [], 'visibility' => 'internal', 'team_ids' => $invoice['team_ids'] ?? [], 'matter_id' => $invoice['matter_id'] ?? null, 'confidentiality' => $invoice['confidentiality'] ?? 'standard', 'denied_user_ids' => $invoice['denied_user_ids'] ?? [], 'generation_count' => $old['generation_count'] ?? 0]);

        return $this->store->transaction(function () use ($user, $id, $data, $version) {
            if ($id) {
                abort_unless(in_array($this->store->get('recurring_invoices', $id)['status'], ['draft', 'paused'], true), 409, 'Schedule is active.');
            }
            $saved = $id ? $this->store->put('recurring_invoices', $id, $data, $version) : $this->store->create('recurring_invoices', $data);
            $this->audit->log($user->id, 'recurring_invoice.saved', 'recurring_invoices', $saved['id']);

            return $saved;
        });
    }

    public function approve($user, string $id): array
    {
        $this->find($user, $id, 'approve');

        return $this->store->transaction(function () use ($user, $id) {
            $record = $this->store->get('recurring_invoices', $id);
            if ($record['status'] === 'active') {
                return $record;
            }
            abort_unless(in_array($record['status'], ['draft', 'paused'], true), 409, 'Completed schedules cannot be reactivated. Create a new schedule.');
            $source = $this->invoices->find($user, $record['invoice_id'], 'write');
            $this->authorizeTemplateMatter($user, $source);
            $record = array_replace($record, ['matter_id' => $source['matter_id'] ?? null, 'team_ids' => $source['team_ids'] ?? [], 'denied_user_ids' => $source['denied_user_ids'] ?? [], 'confidentiality' => $source['confidentiality'] ?? 'standard']);
            $template = array_intersect_key($source, array_flip(['recipient', 'currency', 'items', 'discount_minor', 'matter_id', 'client_ids', 'notes', 'template']));
            $template['items'] = array_map(fn ($item) => array_intersect_key($item, array_flip(['description', 'quantity', 'unit_minor', 'tax_bps'])), $template['items']);
            $template['fee_type'] = 'recurring';
            $saved = $this->store->put('recurring_invoices', $id, array_replace($record, ['status' => 'active', 'approved_template' => $template, 'approved_by' => $user->id, 'approved_at' => now()->toISOString(), 'template_sha256' => hash('sha256', json_encode($template, JSON_THROW_ON_ERROR)), 'pause_reason' => null]), $record['version']);
            $this->audit->log($user->id, 'recurring_invoice.approved', 'recurring_invoices', $id);

            return $saved;
        });
    }

    public function pause($user, string $id): array
    {
        $this->find($user, $id, 'write');

        return $this->store->transaction(function () use ($user, $id) {
            $record = $this->store->get('recurring_invoices', $id);
            abort_if($record['status'] === 'completed', 409, 'Schedule is already completed.');
            $saved = $this->store->put('recurring_invoices', $id, array_replace($record, ['status' => 'paused', 'pause_reason' => 'Paused by user', 'paused_at' => now()->toISOString()]), $record['version']);
            $this->audit->log($user->id, 'recurring_invoice.paused', 'recurring_invoices', $id);

            return $saved;
        });
    }

    /** Queue at most 25 due occurrences; missed periods catch up one per schedule per cron tick. */
    public function queueDue(int $limit = 25, int $budgetSeconds = 5): int
    {
        $count = 0;
        $deadline = microtime(true) + max(1, min($budgetSeconds, 10));
        foreach ($this->store->query('recurring_invoices', ['status' => 'active'], max(1, min($limit, 25)), 'next_run_at', 'asc') as $schedule) {
            if (microtime(true) >= $deadline || Carbon::parse($schedule['next_run_at'])->isFuture()) {
                break;
            }
            $this->outbox->enqueue('billing.recurring', ['schedule_id' => $schedule['id'], 'period' => $schedule['next_run_at']], $schedule['id'].':'.$schedule['next_run_at']);
            $count++;
        }

        return $count;
    }

    /** Internal job handler: approvals authorize draft preparation only; never issue, email, or charge. */
    public function generate(string $id, string $period): ?array
    {
        return $this->store->transaction(function () use ($id, $period) {
            $schedule = $this->store->get('recurring_invoices', $id);
            if ($schedule === null) {
                return null;
            }
            $key = hash('sha256', $id.':'.$period);
            if ($occurrence = $this->store->get('recurring_occurrences', $key)) {
                return $this->store->get('invoices', $occurrence['invoice_id']);
            }
            if ($schedule['status'] !== 'active' || $schedule['next_run_at'] !== $period || Carbon::parse($period)->isFuture()) {
                return null;
            }
            if (! empty($schedule['end_at']) && Carbon::parse($period)->greaterThan(Carbon::parse($schedule['end_at']))) {
                $this->store->put('recurring_invoices', $id, array_replace($schedule, ['status' => 'completed']), $schedule['version']);

                return null;
            }
            $actor = $this->store->get('users', $schedule['approved_by']);
            $user = $actor ? new CrmUser($actor) : null;
            $template = $schedule['approved_template'];
            $matter = ! empty($template['matter_id']) ? $this->store->get('matters', $template['matter_id']) : null;
            $allowed = $this->access->can($user, 'recurring_invoices.approve', $schedule) && $this->access->can($user, 'invoices.write');
            if (! empty($template['matter_id'])) {
                $allowed = $allowed && $matter !== null && $this->access->can($user, 'matters.read', $matter) && ! in_array($matter['status'] ?? '', ['closed', 'archived'], true);
            }
            if (! $allowed) {
                $this->store->put('recurring_invoices', $id, array_replace($schedule, ['status' => 'paused', 'pause_reason' => 'Approval access was revoked or the matter is closed.', 'paused_at' => now()->toISOString()]), $schedule['version']);
                $this->audit->log(null, 'recurring_invoice.permission_paused', 'recurring_invoices', $id);

                return null;
            }
            $invoice = $this->invoices->save($user, $template);
            $this->store->create('recurring_occurrences', ['schedule_id' => $id, 'period' => $period, 'invoice_id' => $invoice['id'], 'template_sha256' => $schedule['template_sha256']], $key);
            $next = $this->nextPeriod($schedule, $period);
            $completed = ! empty($schedule['end_at']) && Carbon::parse($next)->greaterThan(Carbon::parse($schedule['end_at']));
            $this->store->put('recurring_invoices', $id, array_replace($schedule, ['next_run_at' => $next, 'last_generated_at' => now()->toISOString(), 'last_invoice_id' => $invoice['id'], 'generation_count' => $schedule['generation_count'] + 1, 'status' => $completed ? 'completed' : 'active']), $schedule['version']);
            $this->audit->log(null, 'recurring_invoice.draft_generated', 'invoices', $invoice['id'], ['schedule_id' => $id]);

            return $invoice;
        });
    }

    private function authorizeTemplateMatter($user, array $invoice): void
    {
        if (! empty($invoice['matter_id'])) {
            $matter = $this->store->get('matters', $invoice['matter_id']);
            abort_unless($matter !== null, 422, 'Unknown template matter.');
            $this->access->authorize($user, 'matters.read', $matter);
        }
    }

    private function nextPeriod(array $schedule, string $period): string
    {
        $current = Carbon::parse($period)->setTimezone($schedule['timezone']);
        $next = match ($schedule['cadence']) {
            'weekly' => $current->copy()->addWeek(), 'monthly' => $current->copy()->addMonthNoOverflow(), 'quarterly' => $current->copy()->addMonthsNoOverflow(3), 'yearly' => $current->copy()->addYearNoOverflow(), default => throw new \InvalidArgumentException('Unknown recurring cadence.')
        };
        if ($schedule['cadence'] !== 'weekly') {
            $next->day(min($schedule['anchor_day'], $next->daysInMonth));
        }
        $next->setTimeFromTimeString($schedule['anchor_time'] ?? $current->format('H:i:s'));

        return $next->utc()->toISOString();
    }
}
