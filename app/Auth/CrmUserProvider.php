<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Auth;

use App\Contracts\RecordStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

final class CrmUserProvider implements UserProvider
{
    public function __construct(private RecordStore $store) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        $record = $this->store->get('users', (string) $identifier);

        return $record && ($record['status'] ?? 'active') === 'active' && (empty($record['access_expires_at']) || Carbon::parse($record['access_expires_at'])->isFuture()) ? new CrmUser($record) : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = strtolower(trim($credentials['email'] ?? ''));
        $index = $this->store->get('identity_emails', hash('sha256', $email));

        return $index ? $this->retrieveById($index['user_id']) : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return Hash::check($credentials['password'] ?? '', $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        if ($force || Hash::needsRehash($user->getAuthPassword())) {
            $this->store->transaction(function () use ($user, $credentials) {
                $record = $this->store->get('users', $user->getAuthIdentifier());
                // Never restore stale credentials after a concurrent password reset.
                if (! $record || ! Hash::check($credentials['password'], $record['password'])) {
                    return;
                }
                $this->store->put('users', $record['id'], array_merge($record, ['password' => Hash::make($credentials['password'])]), $record['version']);
            });
        }
    }
}
