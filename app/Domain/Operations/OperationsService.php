<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Operations;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OperationsService
{
    public const COLLECTIONS = ['leads', 'contacts', 'matters', 'proceedings', 'tasks', 'team'];

    public function __construct(private RecordStore $store, private Access $access, private Audit $audit, private Outbox $outbox) {}

    public function list($user, string $collection, array $filters = []): array
    {
        $this->collection($collection);
        if (! in_array('client', $user->roles, true) && ! in_array('prospect', $user->roles, true)) {
            $this->access->authorize($user, $collection.'.read');
        }
        $allowed = array_intersect_key($filters, array_flip(['status', 'owner_id', 'matter_id', 'practice', 'jurisdiction', 'source']));
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        return $this->access->cached(function () use ($user, $collection, $allowed, $search) {
            $records = [];
            $matters = [];
            foreach ($this->store->each($collection, $allowed) as $record) {
                if ($search !== '' && ! str_contains(mb_strtolower(implode(' ', array_filter([$record['name'] ?? null, $record['title'] ?? null, $record['email'] ?? null, $record['case_reference'] ?? null, implode(' ', $record['aliases'] ?? []), implode(' ', $record['tags'] ?? [])]))), $search)) {
                    continue;
                }
                if (! $this->access->can($user, $collection.'.read', $record)) {
                    continue;
                }
                if (! empty($record['matter_id'])) {
                    $matter = $matters[$record['matter_id']] ??= $this->store->get('matters', $record['matter_id']) ?? false;
                    if (! $matter || ! $this->access->can($user, 'matters.read', $matter)) {
                        continue;
                    }
                }
                $records[] = $record;
            }

            return $records;
        });
    }

    public function find($user, string $collection, string $id, string $action = 'read'): array
    {
        $this->collection($collection);
        $record = $this->store->get($collection, $id);
        abort_unless($record !== null, 404);
        $this->access->authorize($user, $collection.'.'.$action, $record);
        if (isset($record['matter_id'])) {
            $matter = $this->store->get('matters', $record['matter_id']);
            abort_unless($matter && $this->access->can($user, 'matters.read', $matter), 403);
        }

        return $record;
    }

    public function save($user, string $collection, array $input, ?string $id = null): array
    {
        $this->collection($collection);
        $old = $id ? $this->find($user, $collection, $id, 'write') : null;
        $this->access->authorize($user, $collection.'.write', $old);
        $data = $this->validate($collection, $input, $id !== null);
        $version = $data['version'] ?? null;
        unset($data['version']);
        // Lifecycle decisions cannot be smuggled through ordinary record edits.
        if ($collection === 'leads' && in_array($data['status'] ?? '', ['engaged', 'converted'], true)) {
            throw ValidationException::withMessages(['status' => 'Use reviewed engagement and conversion actions.']);
        }
        if ($collection === 'matters' && ! $old) {
            throw ValidationException::withMessages(['matter' => 'Create matters by converting a conflict-cleared lead with accepted engagement terms.']);
        }
        $this->validateRelationships($user, $data);
        if (isset($data['owner_id']) && $data['owner_id'] !== $user->id) {
            $this->access->authorize($user, $collection.'.assign', $old);
        }
        if (array_key_exists('client_ids', $data) || array_key_exists('team_ids', $data) || array_key_exists('denied_user_ids', $data)) {
            $this->access->authorize($user, $collection.'.share', $old);
        }
        if ($collection === 'leads' && $old && ! empty($old['matter_id']) && array_intersect(array_keys($data), ['name', 'email', 'connected_parties', 'contact_id'])) {
            throw ValidationException::withMessages(['lead' => 'This lead has become a matter; update parties through reviewed matter work.']);
        }
        $record = array_replace($old ?? ['owner_id' => $user->id, 'team_ids' => [], 'client_ids' => [], 'denied_user_ids' => [], 'status' => $this->defaultStatus($collection)], $data);
        if ($collection === 'leads' && $old && array_intersect(array_keys($data), ['name', 'email', 'connected_parties', 'contact_id'])) {
            unset($record['conflict_review'], $record['engagement']);
            $record['status'] = 'conflict_review';
        }
        if (isset($record['matter_id'])) {
            $matter = $this->store->get('matters', $record['matter_id']);
            $record['team_ids'] = array_values(array_unique(array_filter(array_merge([$matter['owner_id'] ?? null], $matter['team_ids'] ?? []))));
            $record['client_ids'] = $data['client_ids'] ?? ($old['client_ids'] ?? []);
            abort_unless(count(array_diff($record['client_ids'], $matter['client_ids'] ?? [])) === 0, 422, 'Task recipients must already have an explicit matter grant.');
            $record['denied_user_ids'] = $matter['denied_user_ids'] ?? [];
            $record['confidentiality'] = $matter['confidentiality'] ?? 'standard';
        }

        return $this->store->transaction(function () use ($collection, $id, $record, $version, $user) {
            $saved = $id ? $this->store->put($collection, $id, $record, $version) : $this->store->create($collection, $record);
            $this->audit->log($user->id, $id ? 'record.updated' : 'record.created', $collection, $saved['id']);
            $this->outbox->enqueue('record.changed', ['collection' => $collection, 'id' => $saved['id'], 'actor_id' => $user->id]);

            return $saved;
        });
    }

    public function delete($user, string $collection, string $id, int $version): void
    {
        $this->find($user, $collection, $id, 'write');
        // Preserve audit and retention history. Archive rather than destructive deletion.
        $this->store->transaction(function () use ($user, $collection, $id, $version) {
            $record = $this->store->get($collection, $id);
            $this->store->put($collection, $id, array_replace($record, ['archived_at' => now()->toIso8601String(), 'status' => 'archived']), $version);
            $this->audit->log($user->id, 'record.archived', $collection, $id);
        });
    }

    public function conflictReview($user, string $id, array $input): array
    {
        $lead = $this->find($user, 'leads', $id, 'review');
        $this->access->authorize($user, 'matters.write');
        $data = Validator::make($input, ['decision' => ['required', Rule::in(['clear', 'flagged', 'rejected'])], 'notes' => 'required|string|max:10000'])->validate();

        return $this->store->transaction(function () use ($user, $id, $lead, $data) {
            $current = $this->store->get('leads', $id);
            abort_if(isset($current['matter_id']), 409, 'This lead has already been converted. Review the matter instead.');
            unset($current['engagement']);
            $review = ['decision' => $data['decision'], 'notes' => $data['notes'], 'reviewer_id' => $user->id, 'reviewed_at' => now()->toIso8601String()];
            $saved = $this->store->put('leads', $id, array_replace($current, ['conflict_review' => $review, 'status' => $data['decision'] === 'clear' ? 'consultation' : 'conflict_review']), $lead['version']);
            $this->store->create('conflict_reviews', array_merge($review, ['lead_id' => $id]));
            $this->audit->log($user->id, 'lead.conflict_reviewed', 'leads', $id, ['decision' => $data['decision']]);

            return $saved;
        });
    }

    public function engagement($user, string $id, array $input): array
    {
        $lead = $this->find($user, 'leads', $id, 'review');
        $this->access->authorize($user, 'matters.write');
        $data = Validator::make($input, ['scope' => 'required|string|max:20000', 'fee_terms' => 'required|string|max:10000', 'accepted_by' => 'required|string|max:200', 'accepted_at' => 'required|date|before_or_equal:now', 'acceptance_reference' => 'required|string|max:1000'])->validate();

        return $this->store->transaction(function () use ($user, $id, $lead, $data) {
            $current = $this->store->get('leads', $id);
            abort_unless(($current['conflict_review']['decision'] ?? '') === 'clear', 409, 'Complete conflict clearance before recording engagement.');
            abort_if(isset($current['matter_id']), 409, 'This lead has already been converted.');
            $engagement = array_merge($data, ['recorded_by' => $user->id, 'recorded_at' => now()->toIso8601String()]);
            $saved = $this->store->put('leads', $id, array_replace($current, ['engagement' => $engagement, 'status' => 'engaged']), $lead['version']);
            $this->audit->log($user->id, 'lead.engagement_accepted', 'leads', $id);

            return $saved;
        });
    }

    public function convert($user, string $id, array $input): array
    {
        $this->find($user, 'leads', $id, 'review');
        $this->access->authorize($user, 'matters.write');
        $data = Validator::make($input, ['title' => 'required|string|max:250', 'practice' => 'required|string|max:120', 'jurisdiction' => 'required|string|max:120', 'team_ids' => 'sometimes|array|max:100', 'team_ids.*' => 'string|max:100'])->validate();
        $this->validateRelationships($user, $data);

        return $this->store->transaction(function () use ($user, $id, $data) {
            $lead = $this->store->get('leads', $id);
            if (isset($lead['matter_id'])) {
                $matter = $this->store->get('matters', $lead['matter_id']);
                $this->access->authorize($user, 'matters.read', $matter);

                return $matter;
            }
            abort_unless(($lead['conflict_review']['decision'] ?? '') === 'clear' && ! empty($lead['engagement']['accepted_at']), 409, 'Conflict clearance and accepted engagement are required.');
            $matter = $this->store->create('matters', array_merge($data, ['owner_id' => $user->id, 'client_id' => $lead['contact_id'] ?? null, 'client_ids' => [], 'team_ids' => $data['team_ids'] ?? [], 'denied_user_ids' => $lead['denied_user_ids'] ?? [], 'lead_id' => $id, 'scope' => $lead['engagement']['scope'], 'fee_terms' => $lead['engagement']['fee_terms'], 'status' => 'active', 'confidentiality' => 'standard', 'engagement' => $lead['engagement']]));
            $this->store->put('leads', $id, array_replace($lead, ['matter_id' => $matter['id'], 'status' => 'converted']), $lead['version']);
            $this->audit->log($user->id, 'lead.converted', 'matters', $matter['id'], ['lead_id' => $id]);
            $this->outbox->enqueue('matter.activated', ['matter_id' => $matter['id'], 'actor_id' => $user->id]);

            return $matter;
        });
    }

    public function conflictMatches($user, string $id): array
    {
        $lead = $this->find($user, 'leads', $id, 'review');
        $this->access->authorize($user, 'matters.write');
        $terms = array_values(array_filter(array_merge([$lead['name'] ?? '', $lead['email'] ?? ''], $lead['connected_parties'] ?? [])));
        $matches = $this->access->cached(function () use ($user, $id, $terms) {
            $matches = [];
            foreach (['contacts', 'leads', 'matters'] as $collection) {
                foreach ($this->store->each($collection) as $record) {
                    if ($record['id'] === $id || ! $this->access->can($user, $collection.'.read', $record)) {
                        continue;
                    }
                    $haystack = mb_strtolower(implode(' ', [$record['name'] ?? '', $record['title'] ?? '', $record['email'] ?? '', implode(' ', $record['aliases'] ?? []), implode(' ', $record['connected_parties'] ?? [])]));
                    foreach ($terms as $term) {
                        if (mb_strlen($term) >= 3 && str_contains($haystack, mb_strtolower($term))) {
                            $matches[] = ['collection' => $collection, 'id' => $record['id'], 'name' => $record['name'] ?? $record['title'] ?? '', 'matched_term' => $term];
                            break;
                        }
                    }
                }
            }

            return $matches;
        });

        return ['matches' => $matches, 'review_required' => true];
    }

    private function collection(string $collection): void
    {
        abort_unless(in_array($collection, self::COLLECTIONS, true), 404);
    }

    private function defaultStatus(string $collection): string
    {
        return match ($collection) {
            'leads' => 'new', 'tasks' => 'open', default => 'active'
        };
    }

    private function validateRelationships($user, array $data): void
    {
        if (! empty($data['matter_id'])) {
            $matter = $this->store->get('matters', $data['matter_id']);
            abort_unless($matter !== null, 422, 'Unknown matter.');
            $this->access->authorize($user, 'matters.write', $matter);
        }
        if (! empty($data['contact_id'])) {
            $contact = $this->store->get('contacts', $data['contact_id']);
            abort_unless($contact !== null, 422, 'Unknown contact.');
            $this->access->authorize($user, 'contacts.read', $contact);
        }
        foreach (['user_id', 'reviewer_id'] as $field) {
            if (! empty($data[$field])) {
                abort_unless($this->store->get('users', $data[$field]) !== null, 422, 'Referenced user does not exist.');
            }
        }
        foreach ($data['dependencies'] ?? [] as $dependencyId) {
            $dependency = $this->store->get('tasks', $dependencyId);
            abort_unless($dependency !== null, 422, 'Task dependency does not exist.');
            $this->access->authorize($user, 'tasks.read', $dependency);
        }
        foreach (array_merge(isset($data['owner_id']) ? [$data['owner_id']] : [], $data['team_ids'] ?? [], $data['client_ids'] ?? [], $data['denied_user_ids'] ?? []) as $id) {
            abort_unless($this->store->get('users', $id) !== null, 422, 'An assigned user does not exist.');
        }
        foreach ($data['client_ids'] ?? [] as $id) {
            $client = $this->store->get('users', $id);
            abort_unless(in_array('client', $client['roles'] ?? [], true) && ! empty($client['email_verified_at']), 422, 'Portal grants require an accepted, verified client account.');
        }
    }

    private function validate(string $collection, array $input, bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $common = ['version' => $updating ? 'required|integer|min:1' : 'prohibited', 'owner_id' => 'sometimes|string|max:100', 'team_ids' => 'sometimes|array|max:100', 'team_ids.*' => 'string|max:100', 'client_ids' => 'sometimes|array|max:100', 'client_ids.*' => 'string|max:100', 'denied_user_ids' => 'sometimes|array|max:100', 'denied_user_ids.*' => 'string|max:100', 'tags' => 'sometimes|array|max:30', 'tags.*' => 'string|max:80', 'notes' => 'sometimes|nullable|string|max:20000'];
        $rules = match ($collection) {
            'leads' => ['name' => "$required|string|max:200", 'email' => 'sometimes|nullable|email|max:254', 'phone' => 'sometimes|nullable|string|max:40', 'status' => ['sometimes', Rule::in(['new', 'contacted', 'intake', 'conflict_review', 'consultation', 'lost', 'closed'])], 'source' => 'sometimes|string|max:120', 'jurisdiction' => 'sometimes|string|max:120', 'issue_category' => 'sometimes|string|max:120', 'next_action' => 'sometimes|nullable|string|max:1000', 'next_action_at' => 'sometimes|nullable|date', 'urgency' => ['sometimes', Rule::in(['normal', 'urgent'])], 'loss_reason' => 'sometimes|nullable|string|max:1000', 'connected_parties' => 'sometimes|array|max:100', 'connected_parties.*' => 'string|max:200', 'contact_id' => 'sometimes|nullable|string|max:100'],
            'contacts' => ['name' => "$required|string|max:200", 'kind' => ['sometimes', Rule::in(['person', 'organization'])], 'email' => 'sometimes|nullable|email|max:254', 'phone' => 'sometimes|nullable|string|max:40', 'address' => 'sometimes|nullable|string|max:2000', 'aliases' => 'sometimes|array|max:30', 'aliases.*' => 'string|max:200', 'safe_contact' => 'sometimes|nullable|string|max:2000', 'authority' => 'sometimes|nullable|string|max:2000', 'relationships' => 'sometimes|array|max:50', 'relationships.*.name' => 'required|string|max:200', 'relationships.*.relationship' => 'required|string|max:100'],
            'matters' => ['title' => "$required|string|max:250", 'practice' => 'sometimes|string|max:120', 'jurisdiction' => 'sometimes|string|max:120', 'scope' => 'sometimes|string|max:20000', 'status' => ['sometimes', Rule::in(['active', 'on_hold', 'closed'])], 'confidentiality' => ['sometimes', Rule::in(['standard', 'restricted'])], 'closure_notes' => 'required_if:status,closed|string|max:20000', 'retention_until' => 'sometimes|nullable|date'],
            'proceedings' => ['title' => "$required|string|max:250", 'matter_id' => "$required|string|max:100", 'court' => 'sometimes|string|max:250', 'case_reference' => 'sometimes|string|max:200', 'hearing_at' => 'sometimes|nullable|date', 'date_source' => 'required_with:hearing_at|string|max:2000', 'order_reference' => 'sometimes|nullable|string|max:2000', 'status' => ['sometimes', Rule::in(['active', 'filed', 'served', 'adjourned', 'closed'])]],
            'tasks' => ['title' => "$required|string|max:250", 'matter_id' => 'sometimes|nullable|string|max:100', 'due_at' => 'sometimes|nullable|date', 'legal_deadline' => 'sometimes|boolean', 'date_source' => 'required_if:legal_deadline,true|string|max:2000', 'reviewer_id' => 'sometimes|nullable|string|max:100', 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])], 'status' => ['sometimes', Rule::in(['open', 'in_progress', 'review', 'done', 'cancelled'])], 'dependencies' => 'sometimes|array|max:50', 'dependencies.*' => 'string|max:100'],
            'team' => ['name' => "$required|string|max:200", 'user_id' => "$required|string|max:100", 'job_title' => 'sometimes|string|max:150', 'office' => 'sometimes|string|max:150', 'skills' => 'sometimes|array|max:30', 'skills.*' => 'string|max:100', 'capacity_hours' => 'sometimes|integer|min:0|max:168', 'status' => ['sometimes', Rule::in(['active', 'on_leave', 'offboarded'])], 'leave_from' => 'sometimes|nullable|date', 'leave_until' => 'sometimes|nullable|date|after_or_equal:leave_from'],
            default => throw new \InvalidArgumentException('Unknown operations collection.'),
        };

        return Validator::make($input, array_merge($common, $rules))->validate();
    }
}
