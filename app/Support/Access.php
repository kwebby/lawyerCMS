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

    public function can(?CrmUser $user, string $ability, ?array $record = null): bool
    {
        if (! $user || ($user->status ?? 'active') !== 'active') {
            return false;
        }
        if ($user->access_expires_at && Carbon::parse($user->access_expires_at)->isPast()) {
            return false;
        }
        $id = $user->id;
        $roles = $user->roles ?? [];
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
        if ($record && isset($record['matter_id']) && in_array($domain, ['documents', 'tasks', 'proceedings', 'conversations', 'messages', 'ai_runs', 'time_entries', 'expenses', 'recurring_invoices'])) {
            $matter = $this->lookup('matters', $record['matter_id']);
            if (! $matter || ! $this->can($user, 'matters.read', $matter)) {
                return false;
            }
        }
        $read = in_array($action, ['read', 'view', 'download', 'list']);
        $client = in_array('client', $roles, true) || in_array('prospect', $roles, true);
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
        $permissions = config('permissions.roles');
        $scope = null;
        foreach ($roles as $role) {
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
        if (! $scope) {
            return false;
        }
        if (! $record) {
            return true;
        }
        $assigned = ($record['owner_id'] ?? null) === $id || ($record['employee_id'] ?? null) === $id || ($record['user_id'] ?? null) === $id || in_array($id, $record['team_ids'] ?? [], true) || in_array($id, $record['member_ids'] ?? [], true);
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
