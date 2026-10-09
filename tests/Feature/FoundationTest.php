<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Support\AiGateway;
use App\Support\Conflict;
use App\Support\JobRunner;
use App\Support\LegalAssistant;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->store = app(RecordStore::class);
        $this->vault = storage_path('framework/testing-vault-'.bin2hex(random_bytes(5)));
        config(['crm.private_path' => $this->vault, 'crm.require_mfa' => false]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    private function user(array $roles = ['owner'], string $email = 'owner@example.test'): CrmUser
    {
        $user = $this->store->create('users', ['name' => 'Test user', 'email' => $email, 'password' => Hash::make('Password123456'), 'roles' => $roles, 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]);
        $this->store->create('identity_emails', ['user_id' => $user['id']], hash('sha256', $email));

        return new CrmUser($user);
    }

    public function test_store_preserves_exact_json_and_detects_stale_revisions(): void
    {
        $record = $this->store->create('contracts', ['name' => 'Alpha', 'amount' => '9007199254740993', 'active' => true, 'tags' => ['a', 'b']]);
        $this->assertSame('9007199254740993', $this->store->get('contracts', $record['id'])['amount']);
        $this->assertCount(1, $this->store->query('contracts', ['name' => 'Alpha']));
        $next = $this->store->put('contracts', $record['id'], array_merge($record, ['name' => 'Beta']), 1);
        $this->assertSame(2, $next['version']);
        $this->expectException(Conflict::class);
        $this->store->put('contracts', $record['id'], $record, 1);
    }

    public function test_transaction_rolls_back_business_record_and_outbox(): void
    {
        try {
            $this->store->transaction(function () {
                $this->store->create('contracts', ['name' => 'Rollback']);
                app(Outbox::class)->enqueue('record.changed', ['record_id' => 'none']);
                throw new \RuntimeException;
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(0, $this->store->query('contracts'));
        $this->assertCount(0, $this->store->query('jobs'));
    }

    public function test_outbox_deduplicates_and_leases_recover_after_expiry(): void
    {
        $outbox = app(Outbox::class);
        $job = $outbox->enqueue('record.changed', [], 'same');
        $again = $outbox->enqueue('record.changed', [], 'same');
        $this->assertSame($job['id'], $again['id']);
        $runner = app(JobRunner::class);
        $lease = $runner->claim($job['id']);
        $this->assertNull($runner->claim($job['id']));
        $this->travel(4)->minutes();
        $second = $runner->claim($job['id']);
        $this->assertNotSame($lease['lease_token'], $second['lease_token']);
        $this->assertSame(2, $second['attempts']);
    }

    public function test_private_files_are_encrypted_and_reject_path_traversal(): void
    {
        $files = app(PrivateFiles::class);
        $path = $files->write('privileged-content');
        $this->assertStringNotContainsString('privileged-content', file_get_contents($this->vault.'/'.$path));
        $this->assertSame('privileged-content', $files->read($path));
        $this->expectException(\InvalidArgumentException::class);
        $files->read('../.env');
    }

    public function test_first_owner_requires_bootstrap_and_cannot_be_recreated(): void
    {
        config(['crm.bootstrap_token' => str_repeat('x', 64)]);
        $data = ['bootstrap_token' => 'bad', 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Password123456', 'password_confirmation' => 'Password123456', 'firm_name' => 'Test Practice'];
        $this->post('/setup', $data)->assertForbidden();
        $data['bootstrap_token'] = str_repeat('x', 64);
        $this->post('/setup', $data)->assertRedirect('/app');
        $this->assertSame(['owner'], $this->store->query('users')[0]['roles']);
        $this->post('/setup', $data)->assertNotFound();
    }

    public function test_public_registration_cannot_self_assign_privileged_roles(): void
    {
        $this->store->create('settings', ['completed' => true], 'installation');
        $this->post('/register', ['name' => 'Visitor', 'email' => 'visitor@example.test', 'password' => 'Password123456', 'password_confirmation' => 'Password123456', 'roles' => ['owner']])->assertRedirect('/login');
        $this->assertSame(['prospect'], $this->store->query('users')[0]['roles']);
        $this->assertNull($this->store->query('users')[0]['email_verified_at']);
        $this->actingAs(new CrmUser($this->store->query('users')[0]))->getJson('/api/v1/settings')->assertForbidden();
    }

    public function test_login_uses_repository_identity_and_regenerates_session(): void
    {
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123456'])->assertRedirect('/app');
        $this->getJson('/api/v1/workspace/dashboard')->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
    }

    public function test_mfa_gate_and_recent_password_confirmation_are_enforced(): void
    {
        $user = $this->user();
        config(['crm.require_mfa' => true]);
        $this->actingAs($user)->getJson('/api/v1/settings')->assertForbidden()->assertJsonPath('mfa_required', true);
        $this->withSession(['auth.mfa' => true])->patchJson('/api/v1/settings', ['section' => 'business', 'data' => []])->assertStatus(423);
        $this->postJson('/api/v1/auth/confirm', ['password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/confirm', ['password' => 'Password123456'])->assertOk();
    }

    public function test_session_revocation_blocks_existing_session(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['auth.epoch' => 99])->getJson('/api/v1/workspace/dashboard')->assertUnauthorized();
    }

    public function test_chat_membership_idempotency_and_revocation(): void
    {
        $owner = $this->user();
        $client = $this->user(['client'], 'client@example.test');
        $conversation = $this->store->create('conversations', ['title' => 'Private', 'member_ids' => [$owner->id], 'client_ids' => [], 'owner_id' => $owner->id, 'last_sequence' => 0]);
        $this->actingAs($owner)->postJson('/api/v1/chat/'.$conversation['id'].'/messages', ['body' => 'Hello', 'idempotency_key' => 'first-message-key'])->assertCreated()->assertJsonPath('data.sequence', 1);
        $this->postJson('/api/v1/chat/'.$conversation['id'].'/messages', ['body' => 'Hello', 'idempotency_key' => 'first-message-key'])->assertCreated()->assertJsonPath('data.sequence', 1);
        $this->assertCount(1, $this->store->query('messages'));
        $this->actingAs($client)->getJson('/api/v1/chat/'.$conversation['id'].'/messages')->assertForbidden();
    }

    public function test_unscanned_uploads_are_never_downloadable(): void
    {
        $this->actingAs($this->user());
        $response = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('notice.txt', 'Private document text.')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'quarantined');
        $id = $response->json('data.id');
        $this->get('/api/v1/files/'.$id.'/download')->assertStatus(423);
        $this->assertCount(1, $this->store->query('jobs', ['type' => 'scan']));
    }

    public function test_notifications_are_private_even_between_administrators(): void
    {
        $owner = $this->user();
        $other = $this->user(['owner'], 'other@example.test');
        $notification = $this->store->create('notifications', ['user_id' => $other->id, 'title' => 'Private', 'category' => 'security', 'read_at' => null]);
        $this->actingAs($owner)->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/'.$notification['id'].'/read')->assertNotFound();
        $this->getJson('/api/v1/workspace/notifications')->assertJsonCount(0, 'data');
    }

    public function test_ai_is_unavailable_until_configured_and_does_not_invent_results(): void
    {
        $this->actingAs($this->user())->postJson('/api/v1/ai/runs', ['kind' => 'draft', 'document_ids' => ['nonexistent']])->assertStatus(422);
        $this->assertCount(0, $this->store->query('ai_runs'));
    }

    public function test_ai_requires_current_authorized_sources_and_human_review(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        app(Settings::class)->save('ai', ['enabled' => true, 'provider' => 'openai', 'model' => 'configured-test-model', 'api_key' => 'test-key', 'daily_limit' => 3]);
        $path = app(PrivateFiles::class)->write(json_encode([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lease expires 30 June.']]]]), 'content');
        $doc = $this->store->create('documents', ['title' => 'Lease', 'owner_id' => $owner->id, 'kind' => 'written', 'status' => 'draft', 'blocks_path' => $path]);
        $run = $this->postJson('/api/v1/ai/runs', ['kind' => 'summary', 'document_ids' => [$doc['id']]])->assertStatus(202)->json('data');
        LegalAssistant::fake(['Draft summary. Source: Lease. Verify the date.']);
        app(AiGateway::class)->run($run['id']);
        $this->getJson('/api/v1/ai/runs/'.$run['id'])->assertOk()->assertJsonPath('data.status', 'review')->assertJsonPath('data.result', 'Draft summary. Source: Lease. Verify the date.');
        $this->store->put('documents', $doc['id'], array_merge($doc, ['title' => 'Changed']), 1);
        $this->withSession(['auth.confirmed_at' => time()])->postJson('/api/v1/ai/runs/'.$run['id'].'/review', ['decision' => 'approved', 'review_notes' => 'Checked against source.', 'expected_version' => 2])->assertStatus(409);
    }

    public function test_expired_public_results_are_inaccessible_before_cleanup(): void
    {
        $run = $this->store->create('ai_runs', ['context' => 'public', 'kind' => 'notice-explainer', 'grant_id' => 'grant', 'status' => 'review', 'expires_at' => now()->subMinute()->toISOString()]);
        $this->withSession(['analyzer_grant' => 'grant'])->get('/tools/results/'.$run['id'])->assertStatus(410);
    }

    public function test_public_analyzers_cannot_upload_without_verified_email(): void
    {
        $this->get('/tools/notice-explainer')->assertOk()->assertSee('This tool is being prepared');
        $this->post('/tools/upload', ['file' => UploadedFile::fake()->create('notice.pdf', 10)])->assertStatus(503);
    }

    public function test_secret_settings_are_encrypted_and_masked(): void
    {
        $settings = app(Settings::class);
        $settings->save('ai', ['api_key' => 'private-key']);
        $this->assertNotSame('private-key', $this->store->get('settings', 'ai')['api_key']);
        $this->assertNull($settings->get('ai')['api_key']);
        $this->assertSame('private-key', $settings->get('ai', true)['api_key']);
    }
}
