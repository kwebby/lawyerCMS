<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Contracts\RecordStore;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private int $queries = 0;

    /** @var list<int> */
    private array $beforeResponse = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['crm.require_mfa' => false]);
        $this->store = app(RecordStore::class);
        $this->store->create('settings', ['completed_at' => now()->toISOString()], 'installation');
        $user = $this->store->create('users', ['name' => 'Existing', 'email' => 'taken@example.test', 'password' => Hash::make('Original-pass-123'), 'roles' => ['client'], 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]);
        $this->store->create('identity_emails', ['user_id' => $user['id']], hash('sha256', 'taken@example.test'));
        DB::listen(function () {
            $this->queries++;
        });
        Event::listen(RequestHandled::class, function () {
            $this->beforeResponse[] = $this->queries;
        });
    }

    private function emails(string $to): array
    {
        return array_values(array_filter($this->store->query('jobs', ['type' => 'email']), fn ($job) => $job['payload']['to'] === $to));
    }

    public function test_registration_answers_the_same_whether_or_not_the_address_has_an_account(): void
    {
        $form = ['name' => 'Visitor', 'password' => 'Visitor-pass-123', 'password_confirmation' => 'Visitor-pass-123'];
        $this->queries = 0;
        $fresh = $this->post('/register', $form + ['email' => 'fresh@example.test']);
        $this->queries = 0;
        $taken = $this->post('/register', $form + ['email' => 'taken@example.test']);
        foreach ([$fresh, $taken] as $response) {
            $response->assertRedirect('/login')->assertSessionHas('status', 'Check your email to confirm your address, then sign in.')->assertSessionHasNoErrors();
        }
        $this->assertSame($this->beforeResponse[0], $this->beforeResponse[1]);
        $this->assertGuest();
        $created = $this->store->query('users', ['email' => 'fresh@example.test']);
        $this->assertSame(['prospect'], $created[0]['roles']);
        $this->assertNull($created[0]['email_verified_at']);
        $this->assertSame('Verify your email', $this->emails('fresh@example.test')[0]['payload']['subject']);
        $this->assertCount(1, $this->store->query('users', ['email' => 'taken@example.test']));
        $this->assertTrue(Hash::check('Original-pass-123', $this->store->query('users', ['email' => 'taken@example.test'])[0]['password']));
        $this->assertSame('Someone tried to register with your email', $this->emails('taken@example.test')[0]['payload']['subject']);
    }

    public function test_password_reset_requests_do_the_same_work_before_responding(): void
    {
        $this->queries = 0;
        $known = $this->postJson('/forgot-password', ['email' => 'taken@example.test'])->assertOk();
        $this->queries = 0;
        $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.test'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame($this->beforeResponse[0], $this->beforeResponse[1]);
        $this->assertCount(1, $this->emails('taken@example.test'));
        $this->assertCount(0, $this->emails('nobody@example.test'));
        $this->assertCount(1, $this->store->query('identity_tokens', ['kind' => 'reset']));
    }

    public function test_sign_in_checks_a_password_hash_even_without_a_usable_account(): void
    {
        $user = $this->store->create('users', ['name' => 'Disabled', 'email' => 'disabled@example.test', 'password' => Hash::make('Disabled-pass-123'), 'roles' => ['lawyer'], 'status' => 'disabled', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]);
        $this->store->create('identity_emails', ['user_id' => $user['id']], hash('sha256', 'disabled@example.test'));
        Hash::shouldReceive('make')->zeroOrMoreTimes()->andReturn(password_hash('placeholder', PASSWORD_BCRYPT));
        Hash::shouldReceive('check')->twice()->andReturnFalse();
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'Any-pass-123'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'disabled@example.test', 'password' => 'Any-pass-123'])->assertSessionHasErrors('email');
    }
}
