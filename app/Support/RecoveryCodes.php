<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;
use Illuminate\Validation\ValidationException;

final class RecoveryCodes
{
    public function __construct(private RecordStore $store, private Audit $audit) {}

    private function digest(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), config('app.key'));
    }

    public function generate(string $userId): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
        }
        $hashes = array_map($this->digest(...), $codes);
        $this->store->transaction(function () use ($userId, $hashes) {
            $user = $this->store->get('users', $userId);
            abort_unless($user && isset($user['mfa_secret']), 422, 'Enroll an authenticator first.');
            $this->store->put('users', $userId, array_merge($user, ['recovery_codes' => $hashes]), $user['version']);
            $this->audit->log($userId, 'identity.recovery_codes_generated', 'users', $userId);
        });

        return $codes;
    }

    public function consume(string $userId, string $code): int
    {
        return $this->store->transaction(function () use ($userId, $code) {
            $user = $this->store->get('users', $userId);
            abort_unless($user !== null, 422);
            $found = false;
            $remaining = [];
            foreach ($user['recovery_codes'] ?? [] as $digest) {
                if (hash_equals($digest, $this->digest($code))) {
                    $found = true;
                } else {
                    $remaining[] = $digest;
                }
            }
            if (! $found) {
                throw ValidationException::withMessages(['code' => 'This recovery code is invalid or has already been used.']);
            }
            $epoch = ($user['session_epoch'] ?? 0) + 1;
            unset($user['mfa_secret'],$user['mfa_pending'],$user['mfa_last_step']);
            $this->store->put('users', $userId, array_merge($user, ['recovery_codes' => $remaining, 'session_epoch' => $epoch]), $user['version']);
            $this->audit->log($userId, 'identity.mfa_recovery', 'users', $userId);

            return $epoch;
        });
    }
}
