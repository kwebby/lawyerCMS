<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Auth;

use App\Contracts\RecordStore;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Per-account limit on second-factor guesses, shared by authenticator and recovery codes and kept on the user record. */
final class MfaLockout
{
    public const ATTEMPTS = 5;

    public const BASE_MINUTES = 15;

    public const MAX_MINUTES = 1440;

    public function __construct(private RecordStore $store, private Audit $audit, private Outbox $outbox) {}

    /**
     * Run one verification while holding the account record. $verify receives the current record and returns a result,
     * or throws ValidationException for a wrong code; only those count. Concurrent attempts serialize on the record
     * (row lock, expected version), so they cannot share a stale counter. Success clears the counter and escalation.
     */
    public function attempt(string $userId, callable $verify): mixed
    {
        [$result, $error] = $this->store->transaction(function () use ($userId, $verify) {
            $user = $this->store->get('users', $userId);
            abort_unless($user !== null, 422);
            if (! empty($user['mfa_locked_until']) && Carbon::parse($user['mfa_locked_until'])->isFuture()) {
                return [null, $this->locked($user['mfa_locked_until'])];
            }
            try {
                $result = $verify($user);
            } catch (ValidationException $e) {
                return [null, $this->fail($user) ?? $e];
            }
            $current = $this->store->get('users', $userId);
            if (! empty($current['mfa_failures']) || ! empty($current['mfa_lockouts']) || ! empty($current['mfa_locked_until'])) {
                unset($current['mfa_failures'], $current['mfa_lockouts'], $current['mfa_locked_until']);
                $this->store->put('users', $userId, $current, $current['version']);
            }

            return [$result, null];
        });
        if ($error) {
            throw $error;
        }

        return $result;
    }

    private function fail(array $user): ?ValidationException
    {
        $failures = ($user['mfa_failures'] ?? 0) + 1;
        if ($failures < self::ATTEMPTS) {
            $this->store->put('users', $user['id'], array_merge($user, ['mfa_failures' => $failures]), $user['version']);

            return null;
        }
        $lockouts = ($user['mfa_lockouts'] ?? 0) + 1;
        $minutes = (int) min(self::MAX_MINUTES, self::BASE_MINUTES * 2 ** min($lockouts - 1, 10));
        $until = now()->addMinutes($minutes)->toISOString();
        $this->store->put('users', $user['id'], array_merge($user, ['mfa_failures' => 0, 'mfa_lockouts' => $lockouts, 'mfa_locked_until' => $until]), $user['version']);
        $this->audit->log($user['id'], 'identity.mfa_locked', 'users', $user['id'], ['minutes' => $minutes, 'lockouts' => $lockouts]);
        $this->store->create('notifications', ['user_id' => $user['id'], 'title' => 'Sign-in verification paused after repeated incorrect codes', 'category' => 'security', 'severity' => 'warning', 'action_required' => false, 'read_at' => null]);
        $this->outbox->enqueue('email', ['to' => $user['email'], 'subject' => 'LawyerCMS sign-in verification paused', 'body' => 'Several incorrect verification codes were entered for your account, so verification is paused until '.Carbon::parse($until)->format('Y-m-d H:i').' UTC. If this was not you, reset your password and tell your administrator.'], 'mfa-locked-'.$user['id'].'-'.$until);

        return $this->locked($until);
    }

    private function locked(string $until): ValidationException
    {
        return ValidationException::withMessages(['code' => 'Too many incorrect codes. Try again after '.Carbon::parse($until)->format('Y-m-d H:i').' UTC.'])->status(429);
    }
}
