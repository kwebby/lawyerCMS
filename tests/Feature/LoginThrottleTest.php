<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Contracts\RecordStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['crm.require_mfa' => false]);
        $store = app(RecordStore::class);
        foreach (['alpha@example.test', 'beta@example.test'] as $email) {
            $user = $store->create('users', ['name' => 'Person', 'email' => $email, 'password' => Hash::make('Password123456'), 'roles' => ['lawyer'], 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]);
            $store->create('identity_emails', ['user_id' => $user['id']], hash('sha256', $email));
        }
    }

    private function attempt(string $email, string $ip, string $password = 'wrong-password', array $headers = []): mixed
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => $email, 'password' => $password], $headers);
    }

    public function test_sign_in_is_limited_per_account_and_address_with_a_looser_address_ceiling(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('alpha@example.test', '192.0.2.10')->assertSessionHasErrors('email');
        }
        $this->attempt(' ALPHA@example.test', '192.0.2.10', 'Password123456')->assertStatus(429);
        // Another account from the same address, and the same account from elsewhere, are unaffected.
        $this->attempt('beta@example.test', '192.0.2.10', 'Password123456')->assertRedirect('/app');
        $this->post('/logout');
        $this->attempt('alpha@example.test', '192.0.2.20', 'Password123456')->assertRedirect('/app');
        $this->post('/logout');
        for ($i = 0; $i < 30; $i++) {
            $this->attempt("visitor{$i}@example.test", '192.0.2.30')->assertSessionHasErrors('email');
        }
        $this->attempt('beta@example.test', '192.0.2.30', 'Password123456')->assertStatus(429);
    }

    public function test_forwarded_client_addresses_are_honoured_only_from_configured_proxies(): void
    {
        Route::middleware('web')->get('/_client-address', fn (Request $request) => $request->ip());
        $forwarded = ['X-Forwarded-For' => '203.0.113.7'];
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/_client-address', $forwarded)->assertSeeText('10.0.0.5');
        config(['auth.trusted_proxies' => '10.0.0.5, 10.0.1.0/24']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/_client-address', $forwarded)->assertSeeText('203.0.113.7');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.9'])->get('/_client-address', $forwarded)->assertSeeText('203.0.113.7');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get('/_client-address', $forwarded)->assertSeeText('198.51.100.9');
        // Behind a trusted proxy, each client gets its own sign-in bucket rather than sharing the proxy's.
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('alpha@example.test', '10.0.0.5', 'wrong-password', ['X-Forwarded-For' => '203.0.113.7'])->assertSessionHasErrors('email');
        }
        $this->attempt('alpha@example.test', '10.0.0.5', 'Password123456', ['X-Forwarded-For' => '203.0.113.8'])->assertRedirect('/app');
    }
}
