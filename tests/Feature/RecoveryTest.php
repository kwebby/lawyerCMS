<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Support\RecoveryCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_codes_are_hashed_one_use_and_revoke_sessions(): void
    {
        $this->withoutVite();
        $store = app(RecordStore::class);
        $user = $store->create('users', ['name' => 'Owner', 'email' => 'owner@example.test', 'roles' => ['owner'], 'password' => Hash::make('Password123456'), 'mfa_secret' => 'encrypted-fixture', 'session_epoch' => 0]);
        $codes = app(RecoveryCodes::class)->generate($user['id']);
        $this->assertCount(10, $codes);
        $this->assertNotContains($codes[0], $store->get('users', $user['id'])['recovery_codes']);
        $this->actingAs(new CrmUser($user))->withSession(['auth.epoch' => 0])->post('/mfa/recover', ['code' => $codes[0]])->assertRedirect('/mfa');
        $current = $store->get('users', $user['id']);
        $this->assertSame(1, $current['session_epoch']);
        $this->assertArrayNotHasKey('mfa_secret', $current);
        $this->actingAs(new CrmUser($current))->postJson('/mfa/recover', ['code' => $codes[0]])->assertUnprocessable();
    }

    public function test_recovery_code_regeneration_requires_mfa_and_recent_authentication(): void
    {
        $store = app(RecordStore::class);
        $user = $store->create('users', ['name' => 'Owner', 'email' => 'owner@example.test', 'roles' => ['owner'], 'password' => Hash::make('Password123456'), 'mfa_secret' => 'fixture']);
        $this->actingAs(new CrmUser($user))->postJson('/mfa/recovery-codes')->assertStatus(423);
        $this->withSession(['auth.confirmed_at' => time()])->postJson('/mfa/recovery-codes')->assertForbidden();
    }
}
