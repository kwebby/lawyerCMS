<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Support\RecoveryCodes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;

final class MfaLockoutTest extends BusinessTestCase
{
    private TOTP $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = TOTP::generate();
        $record = $this->store->get('users', $this->owner->id);
        $this->store->put('users', $record['id'], array_replace($record, ['mfa_secret' => Crypt::encryptString($this->totp->getSecret())]), $record['version']);
    }

    private function wrongCode(): string
    {
        $step = (int) floor(time() / 30);
        $valid = array_map(fn ($s) => $this->totp->at($s * 30), [$step - 1, $step, $step + 1]);
        for ($code = 0; in_array(sprintf('%06d', $code), $valid, true); $code++);

        return sprintf('%06d', $code);
    }

    private function lockedFor(): float
    {
        return now()->diffInMinutes(Carbon::parse($this->store->get('users', $this->owner->id)['mfa_locked_until']));
    }

    public function test_five_wrong_codes_lock_verification_escalate_and_reset_on_success(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/mfa', ['code' => $this->wrongCode()])->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson('/mfa', ['code' => $this->wrongCode()])->assertStatus(429)->assertJsonPath('errors.code.0', fn ($m) => str_contains($m, 'Too many incorrect codes'));
        $this->postJson('/mfa', ['code' => $this->totp->now()])->assertStatus(429);
        $this->assertEqualsWithDelta(15, $this->lockedFor(), 0.1);
        $this->assertCount(1, $this->store->query('audit', ['action' => 'identity.mfa_locked']));
        $this->assertCount(1, $this->store->query('notifications', ['user_id' => $this->owner->id, 'category' => 'security']));
        $this->assertCount(1, array_filter($this->store->query('jobs', ['type' => 'email']), fn ($job) => $job['payload']['to'] === $this->owner->email));
        // A second lockout doubles the pause.
        $this->travel(16)->minutes();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/mfa', ['code' => $this->wrongCode()]);
        }
        $this->assertEqualsWithDelta(30, $this->lockedFor(), 0.1);
        $this->travel(31)->minutes();
        $this->post('/mfa', ['code' => $this->totp->now()])->assertRedirect('/app');
        $record = $this->store->get('users', $this->owner->id);
        foreach (['mfa_failures', 'mfa_lockouts', 'mfa_locked_until'] as $field) {
            $this->assertArrayNotHasKey($field, $record);
        }
        // The counter lives on the stored record, not in the request's user copy.
        $this->store->put('users', $record['id'], array_replace($record, ['mfa_failures' => 4]), $record['version']);
        $this->travel(2)->minutes();
        $this->postJson('/mfa', ['code' => $this->wrongCode()])->assertStatus(429);
        $this->assertEqualsWithDelta(15, $this->lockedFor(), 0.1);
    }

    public function test_recovery_codes_share_the_account_lockout(): void
    {
        $codes = app(RecoveryCodes::class)->generate($this->owner->id);
        $wrong = 'AAAAAAAA-AAAAAAAA-AAAAAAAA-AAAAAAAA';
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/mfa/recover', ['code' => $wrong])->assertUnprocessable();
        }
        $this->postJson('/mfa/recover', ['code' => $wrong])->assertStatus(429)->assertJsonValidationErrors('code');
        $this->travel(2)->minutes();
        $this->postJson('/mfa/recover', ['code' => $codes[0]])->assertStatus(429)->assertJsonValidationErrors('code');
        $this->postJson('/mfa', ['code' => $this->totp->now()])->assertStatus(429);
        $this->assertCount(10, $this->store->get('users', $this->owner->id)['recovery_codes']);
        $this->travel(15)->minutes();
        $this->post('/mfa/recover', ['code' => $codes[0]])->assertRedirect('/mfa');
        $this->assertCount(9, $this->store->get('users', $this->owner->id)['recovery_codes']);
    }
}
