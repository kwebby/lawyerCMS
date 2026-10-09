<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Auth\CrmUserProvider;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;

final class SecurityRegressionTest extends BusinessTestCase
{
    public function test_password_reset_invalidates_other_previously_issued_links(): void
    {
        $this->store->create('identity_emails', ['user_id' => $this->owner->id], hash('sha256', $this->owner->email));
        $this->postJson('/forgot-password', ['email' => $this->owner->email])->assertOk();
        $this->postJson('/forgot-password', ['email' => $this->owner->email])->assertOk();
        $jobs = array_values(array_filter($this->store->query('jobs'), fn ($job) => $job['type'] === 'email'));
        $this->assertCount(2, $jobs);
        preg_match('~/password/reset/([A-Za-z0-9]+)~', $jobs[0]['payload']['body'], $first);
        preg_match('~/password/reset/([A-Za-z0-9]+)~', $jobs[1]['payload']['body'], $second);
        $data = ['password' => 'Changed-password-987', 'password_confirmation' => 'Changed-password-987'];
        $this->post('/password/reset/'.$first[1], $data)->assertRedirect('/login');
        $this->postJson('/password/reset/'.$second[1], $data)->assertUnprocessable();
        $this->assertSame(1, $this->store->get('users', $this->owner->id)['session_epoch']);
    }

    public function test_stale_password_rehash_cannot_undo_a_password_reset(): void
    {
        $record = $this->store->get('users', $this->owner->id);
        $this->store->put('users', $record['id'], array_replace($record, ['password' => Hash::make('new-password-987'), 'session_epoch' => 1]), $record['version']);
        app(CrmUserProvider::class)->rehashPasswordIfRequired($this->owner, ['password' => 'test-password-123'], true);
        $this->assertTrue(Hash::check('new-password-987', $this->store->get('users', $record['id'])['password']));
        $this->assertFalse(Hash::check('test-password-123', $this->store->get('users', $record['id'])['password']));
    }

    public function test_mfa_tracks_the_actual_accepted_timestep_and_rejects_replay(): void
    {
        $totp = TOTP::generate();
        $record = $this->store->get('users', $this->owner->id);
        $this->store->put('users', $record['id'], array_replace($record, ['mfa_secret' => Crypt::encryptString($totp->getSecret())]), $record['version']);
        $futureStep = (int) floor(time() / 30) + 1;
        $code = $totp->at($futureStep * 30);
        $this->post('/mfa', ['code' => $code])->assertRedirect('/app');
        $this->assertSame($futureStep, $this->store->get('users', $record['id'])['mfa_last_step']);
        $this->postJson('/mfa', ['code' => $code])->assertUnprocessable();
    }

    public function test_message_idempotency_conflict_monotonic_read_and_permission_revocation(): void
    {
        $lawyer = $this->user('lawyer');
        $conversation = $this->postJson('/api/v1/chat', ['title' => 'Review', 'member_ids' => [$lawyer->id], 'client_visible' => false])->assertCreated()->json('data');
        $url = '/api/v1/chat/'.$conversation['id'];
        $first = $this->postJson($url.'/messages', ['body' => 'First message', 'idempotency_key' => 'message-key-001'])->assertCreated()->json('data');
        $this->postJson($url.'/messages', ['body' => 'First message', 'idempotency_key' => 'message-key-001'])->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->postJson($url.'/messages', ['body' => 'Different message', 'idempotency_key' => 'message-key-001'])->assertConflict();
        $this->postJson($url.'/read', ['sequence' => 1])->assertOk()->assertJsonPath('data.sequence', 1);
        $this->postJson($url.'/read', ['sequence' => 0])->assertOk()->assertJsonPath('data.sequence', 1);
        $this->actingAs($lawyer)->getJson($url.'/messages')->assertOk()->assertJsonCount(1, 'data');
        $this->store->create('roles', ['permissions' => ['pages.read' => 'assigned']], 'lawyer');
        $this->getJson($url.'/messages')->assertForbidden();
    }

    public function test_client_upload_is_quarantined_then_readable_by_self_and_matter_team_only(): void
    {
        $client = $this->user('client');
        $other = $this->user('client');
        $matter = $this->store->create('matters', ['title' => 'Shared matter', 'owner_id' => $this->owner->id, 'client_ids' => [$client->id, $other->id], 'team_ids' => [], 'confidentiality' => 'standard']);
        $file = UploadedFile::fake()->createWithContent('client-note.txt', 'Private client note');
        $document = $this->actingAs($client)->post('/api/v1/files', ['file' => $file, 'matter_id' => $matter['id']], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->get('/api/v1/files/'.$document['id'].'/download')->assertStatus(423);
        $record = $this->store->get('documents', $document['id']);
        $this->store->put('documents', $record['id'], array_replace($record, ['status' => 'clean']), $record['version']);
        $this->get('/api/v1/files/'.$document['id'].'/download')->assertOk();
        $this->actingAs($other)->get('/api/v1/files/'.$document['id'].'/download')->assertForbidden();
        $this->actingAs($this->owner)->get('/api/v1/files/'.$document['id'].'/download')->assertOk();
    }

    public function test_task_sharing_requires_existing_matter_grant_and_fresh_authentication(): void
    {
        $client = $this->user('client');
        $other = $this->user('client');
        $matter = $this->store->create('matters', ['title' => 'Shared matter', 'owner_id' => $this->owner->id, 'client_ids' => [$client->id], 'team_ids' => []]);
        $task = $this->postJson('/api/v1/records/tasks', ['title' => 'Client task', 'matter_id' => $matter['id']])->assertCreated()->json('data');
        $url = '/api/v1/records/tasks/'.$task['id'];
        $this->patchJson($url, ['version' => $task['version'], 'client_ids' => [$other->id]])->assertUnprocessable();
        $this->withSession(['auth.confirmed_at' => 0])->patchJson($url, ['version' => $task['version'], 'client_ids' => [$client->id]])->assertStatus(423);
        $this->withSession(['auth.confirmed_at' => time()])->patchJson($url, ['version' => $task['version'], 'client_ids' => [$client->id]])->assertOk();
        $this->actingAs($client)->getJson($url)->assertOk();
    }

    public function test_expired_collaborator_cannot_authenticate_or_access_assigned_records(): void
    {
        $collaborator = $this->user('collaborator', ['access_expires_at' => now()->subMinute()->toISOString()]);
        $record = $this->store->create('matters', ['title' => 'Assigned', 'owner_id' => $this->owner->id, 'team_ids' => [$collaborator->id], 'client_ids' => []]);
        $this->assertNull(app(CrmUserProvider::class)->retrieveById($collaborator->id));
        $this->assertFalse(app(Access::class)->can($collaborator, 'matters.read', $record));
    }

    public function test_chat_cursor_can_retrieve_messages_after_ten_thousand(): void
    {
        $conversation = $this->store->create('conversations', ['title' => 'Long-running matter', 'member_ids' => [$this->owner->id], 'client_ids' => [], 'owner_id' => $this->owner->id, 'last_sequence' => 10002]);
        foreach ([9999, 10000, 10001, 10002] as $sequence) {
            $this->store->create('messages', ['conversation_id' => $conversation['id'], 'sequence' => $sequence, 'sequence_bucket' => intdiv($sequence - 1, 100), 'body' => 'Message '.$sequence, 'member_ids' => [$this->owner->id], 'client_ids' => [], 'owner_id' => $this->owner->id]);
        }
        $this->getJson('/api/v1/chat/'.$conversation['id'].'/messages?after=9999')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.sequence', 10000)->assertJsonPath('next_after', 10002)->assertJsonPath('has_more', false);
    }
}
