<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

final class Access
{
    /** @var array<string, array<string, ?array>>|null */
    private ?array $cache = null;

    public function __construct(private RecordStore $store) {}

    /** Run read-only checks with role and parent-matter lookups cached for the callback's duration; do not wrap writes. */
    public function cached(callable $callback): mixed
    {
        if ($this->cache !== null) {
            return $callback();
        }
        $this->cache = [];
        try {
            return $callback();
        } finally {
            $this->cache = null;
        }
    }

    /** The records this user may perform the ability on, checked in one cached pass. */
    public function filter(?CrmUser $user, string $ability, iterable $records): array
    {
        return $this->cached(function () use ($user, $ability, $records) {
            $allowed = [];
            foreach ($records as $record) {
                if ($this->can($user, $ability, $record)) {
                    $allowed[] = $record;
                }
            }

            return $allowed;
        });
    }

    private function lookup(string $collection, string $id): ?array
    {
        if ($this->cache === null) {
            return $this->store->get($collection, $id);
        }
        if (! array_key_exists($id, $this->cache[$collection] ?? [])) {
            $this->cache[$collection][$id] = $this->store->get($collection, $id);
        }

        return $this->cache[$collection][$id];
    }

    private function active(?CrmUser $user): bool
    {
        return $user && ($user->status ?? 'active') === 'active' && ! ($user->access_expires_at && Carbon::parse($user->access_expires_at)->isPast());
    }

    private function isClient(CrmUser $user): bool
    {
        return (bool) array_intersect(['client', 'prospect'], $user->roles ?? []);
    }

    private function assigned(string $id, array $record): bool
    {
        return ($record['owner_id'] ?? null) === $id || ($record['employee_id'] ?? null) === $id || ($record['user_id'] ?? null) === $id || in_array($id, $record['team_ids'] ?? [], true) || in_array($id, $record['member_ids'] ?? [], true);
    }

    /** The broadest scope the user's roles grant for an ability ('firm', 'team' or 'assigned'), or null. Record walls are not applied; portal users hold no staff scope. */
    public function scope(?CrmUser $user, string $ability): ?string
    {
        if (! $this->active($user) || $this->isClient($user)) {
            return null;
        }
        $domain = explode('.', $ability, 2)[0];
        $permissions = config('permissions.roles');
        $scope = null;
        foreach ($user->roles ?? [] as $role) {
            $custom = $this->lookup('roles', $role);
            $rules = $custom['permissions'] ?? $permissions[$role] ?? [];
            foreach ([$ability, $domain.'.*', '*'] as $key) {
                if (isset($rules[$key])) {
                    $candidate = $rules[$key];
                    if ($candidate === 'firm' || $scope === null) {
                        $scope = $candidate;
                    }
                }
            }
        }

        return $scope;
    }

    public function can(?CrmUser $user, string $ability, ?array $record = null): bool
    {
        if (! $this->active($user)) {
            return false;
        }
        $id = $user->id;
        if ($record && in_array($id, $record['denied_user_ids'] ?? [], true)) {
            return false;
        }
        [$domain,$action] = array_pad(explode('.', $ability, 2), 2, 'read');
        if ($record && $domain === 'notifications' && ($record['user_id'] ?? null) !== $id) {
            return false;
        }
        if ($record && in_array($domain, ['conversations', 'messages']) && ! in_array($id, $record['member_ids'] ?? [], true)) {
            return false;
        }
        $client = $this->isClient($user);
        if ($record && isset($record['matter_id']) && in_array($domain, ['documents', 'tasks', 'proceedings', 'conversations', 'messages', 'ai_runs', 'time_entries', 'expenses', 'recurring_invoices', 'leads'])) {
            $matter = $this->lookup('matters', $record['matter_id']);
            if (! $matter || ! $this->can($user, 'matters.read', $matter)) {
                return false;
            }
        }
        // Staff see an invoice only through the live matter's wall; a payer sees invoices shared with them without legal-file access.
        if ($record && ! empty($record['matter_id']) && $domain === 'invoices' && ! $client) {
            $matter = $this->lookup('matters', $record['matter_id']);
            if (! $matter || in_array($id, $matter['denied_user_ids'] ?? [], true) || (($matter['confidentiality'] ?? '') === 'restricted' && ! $this->assigned($id, $matter))) {
                return false;
            }
        }
        if ($record && $domain === 'ai_runs') {
            foreach ($record['source_versions'] ?? [] as $source) {
                $document = $this->lookup('documents', (string) ($source['id'] ?? ''));
                if (! $document || ! $this->can($user, 'documents.read', $document)) {
                    return false;
                }
            }
        }
        $read = in_array($action, ['read', 'view', 'download', 'list']);
        if ($client) {
            if (! $read || ! $record) {
                return false;
            }
            if (! in_array($domain, ['matters', 'documents', 'conversations', 'messages', 'invoices', 'payments', 'notifications', 'ai_runs', 'tasks'])) {
                return false;
            }
            if ($domain === 'notifications') {
                return ($record['user_id'] ?? null) === $id;
            }

            return in_array($id, $record['client_ids'] ?? [], true) && ($record['visibility'] ?? 'shared') !== 'internal';
        }
        if ($domain === 'payslips' && $read && ($record['employee_id'] ?? null) === $id) {
            return true;
        }
        $scope = $this->scope($user, $ability);
        if (! $scope) {
            return false;
        }
        if (! $record) {
            return true;
        }
        $assigned = $this->assigned($id, $record);
        if (($record['confidentiality'] ?? '') === 'restricted' && ! $assigned) {
            return false;
        }
        if ($scope === 'firm') {
            return true;
        }

        return $assigned;
    }

    public function authorize(?CrmUser $user, string $ability, ?array $record = null): void
    {
        if (! $this->can($user, $ability, $record)) {
            throw new AuthorizationException('You do not have access to this record or action.');
        }
    }
}
