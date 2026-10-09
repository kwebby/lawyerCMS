<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTakeoverTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['crm.require_mfa' => false]);
        $this->store = app(RecordStore::class);
        $this->store->create('settings', ['completed_at' => now()->toISOString()], 'installation');
    }

    private function account(array $roles, string $email, array $extra = []): CrmUser
    {
        $user = $this->store->create('users', array_merge(['name' => 'Person', 'email' => $email, 'password' => Hash::make('Password123456'), 'roles' => $roles, 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()], $extra));
        $this->store->create('identity_emails', ['user_id' => $user['id']], hash('sha256', $email));

        return new CrmUser($user);
    }

    private function staff(array $roles = ['owner'], string $email = 'owner@example.test', array $extra = []): static
    {
        return $this->actingAs($this->account($roles, $email, $extra))->withSession(['auth.confirmed_at' => time()]);
    }

    public function test_unverified_self_registration_cannot_be_granted_firm_or_client_access(): void
    {
        $squatter = $this->account(['prospect'], 'new.lawyer@example.test', ['email_verified_at' => null, 'password' => Hash::make('Attacker-pass-123')]);
        $this->staff();
        $this->patchJson('/api/v1/users/'.$squatter->id, ['roles' => ['lawyer']])->assertUnprocessable();
        $this->patchJson('/api/v1/users/'.$squatter->id, ['roles' => ['client']])->assertUnprocessable();
        $this->assertSame(['prospect'], $this->store->get('users', $squatter->id)['roles']);
    }

    public function test_invitation_takes_over_an_unverified_prospect_and_revokes_its_credentials(): void
    {
        $squatter = $this->account(['prospect'], 'new.lawyer@example.test', ['email_verified_at' => null, 'password' => Hash::make('Attacker-pass-123'), 'mfa_secret' => 'attacker-secret', 'mfa_last_step' => 5, 'recovery_codes' => ['attacker-digest']]);
        $this->staff();
        $this->account(['client'], 'verified@example.test');
        $this->postJson('/api/v1/invitations', ['email' => 'verified@example.test', 'roles' => ['lawyer']])->assertUnprocessable();
        $this->postJson('/api/v1/invitations', ['email' => 'New.Lawyer@example.test', 'roles' => ['lawyer']])->assertCreated();
        $job = array_values(array_filter($this->store->query('jobs'), fn ($job) => $job['type'] === 'email' && $job['payload']['to'] === 'New.Lawyer@example.test'))[0];
        preg_match('~/invite/([A-Za-z0-9]+)~', $job['payload']['body'], $token);
        $this->post('/logout');
        $this->post('/invite/'.$token[1], ['name' => 'Real Lawyer', 'password' => 'Real-lawyer-pass-456', 'password_confirmation' => 'Real-lawyer-pass-456'])->assertRedirect('/app');
        $account = $this->store->get('users', $squatter->id);
        $this->assertSame(['lawyer'], $account['roles']);
        $this->assertNotEmpty($account['email_verified_at']);
        $this->assertTrue(Hash::check('Real-lawyer-pass-456', $account['password']));
        $this->assertSame(1, $account['session_epoch']);
        foreach (['mfa_secret', 'mfa_last_step', 'recovery_codes'] as $field) {
            $this->assertArrayNotHasKey($field, $account);
        }
        $this->assertCount(1, $this->store->query('users', ['email' => 'new.lawyer@example.test']));
        $this->actingAs(new CrmUser($account))->withSession(['auth.epoch' => 0])->getJson('/api/v1/workspace/dashboard')->assertUnauthorized();
        $this->post('/logout');
        $this->post('/login', ['email' => 'new.lawyer@example.test', 'password' => 'Attacker-pass-123'])->assertSessionHasErrors('email');
    }

    public function test_authenticator_enrolment_requires_a_verified_email(): void
    {
        $this->actingAs($this->account(['prospect'], 'visitor@example.test', ['email_verified_at' => null]));
        $this->get('/mfa')->assertForbidden();
        $this->postJson('/mfa', ['code' => '123456'])->assertForbidden();
        $this->assertArrayNotHasKey('mfa_pending', $this->store->query('users')[0]);
    }

    public function test_email_verification_only_counts_from_the_account_holders_session(): void
    {
        $squatter = $this->account(['prospect'], 'victim@example.test', ['email_verified_at' => null]);
        $token = str_repeat('v', 64);
        $this->store->create('identity_tokens', ['user_id' => $squatter->id, 'kind' => 'verify', 'issued_epoch' => 0, 'expires_at' => now()->addHour()->toISOString(), 'used_at' => null], hash('sha256', $token));
        $this->get('/verify/'.$token)->assertRedirect('/login');
        $this->actingAs($this->account(['client'], 'someone@example.test'))->get('/verify/'.$token)->assertForbidden();
        $this->assertNull($this->store->get('users', $squatter->id)['email_verified_at']);
        $this->actingAs($squatter)->get('/verify/'.$token)->assertRedirect('/portal');
        $this->assertNotNull($this->store->get('users', $squatter->id)['email_verified_at']);
    }

    public function test_only_owners_manage_owner_and_administrator_access(): void
    {
        $owner = $this->account(['owner'], 'owner@example.test');
        $admin = $this->account(['admin'], 'admin2@example.test');
        $lawyer = $this->account(['lawyer'], 'lawyer@example.test');
        $this->staff(['admin'], 'admin@example.test');
        $this->patchJson('/api/v1/users/'.$lawyer->id, ['roles' => ['admin']])->assertForbidden();
        $this->patchJson('/api/v1/users/'.$lawyer->id, ['roles' => ['owner']])->assertForbidden();
        $this->patchJson('/api/v1/users/'.$admin->id, ['roles' => ['lawyer']])->assertForbidden();
        $this->patchJson('/api/v1/users/'.$owner->id, ['roles' => ['lawyer']])->assertForbidden();
        $this->patchJson('/api/v1/users/'.$owner->id, ['status' => 'disabled'])->assertForbidden();
        $this->assertSame(['owner'], $this->store->get('users', $owner->id)['roles']);
        $this->patchJson('/api/v1/users/'.$lawyer->id, ['roles' => ['paralegal']])->assertOk();
        $this->actingAs($owner)->patchJson('/api/v1/users/'.$lawyer->id, ['roles' => ['admin']])->assertOk();
    }

    public function test_the_last_active_owner_cannot_be_demoted_or_disabled(): void
    {
        $permanent = $this->account(['owner'], 'owner@example.test');
        $this->staff(['owner'], 'leaving.owner@example.test', ['access_expires_at' => now()->addDays(5)->toISOString()]);
        $this->patchJson('/api/v1/users/'.$permanent->id, ['status' => 'disabled'])->assertUnprocessable();
        $this->patchJson('/api/v1/users/'.$permanent->id, ['roles' => ['partner']])->assertUnprocessable();
        $this->patchJson('/api/v1/users/'.$permanent->id, ['access_expires_at' => now()->addDays(10)->toDateString()])->assertUnprocessable();
        $this->assertSame('active', $this->store->get('users', $permanent->id)['status']);
        $this->account(['owner'], 'second.owner@example.test');
        $this->patchJson('/api/v1/users/'.$permanent->id, ['status' => 'disabled'])->assertOk();
    }

    public function test_null_expiry_never_clears_a_collaborator_expiry(): void
    {
        $expiry = now()->addDays(10)->startOfSecond()->toISOString();
        $collaborator = $this->account(['collaborator'], 'collab@example.test', ['access_expires_at' => $expiry]);
        $lawyer = $this->account(['lawyer'], 'lawyer@example.test');
        $this->staff();
        $this->patchJson('/api/v1/users/'.$collaborator->id, ['access_expires_at' => null])->assertOk();
        $this->assertSame($expiry, $this->store->get('users', $collaborator->id)['access_expires_at']);
        $this->patchJson('/api/v1/users/'.$lawyer->id, ['roles' => ['collaborator'], 'access_expires_at' => null])->assertOk();
        $this->assertTrue(now()->addDays(29)->isBefore($this->store->get('users', $lawyer->id)['access_expires_at']));
    }
}
