<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Communications\NotificationDelivery;
use App\Support\JobRunner;
use App\Support\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommunicationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $roles = ['owner']): CrmUser
    {
        config(['crm.require_mfa' => false]);

        return new CrmUser(app(RecordStore::class)->create('users', ['name' => 'Person', 'email' => 'person@example.test', 'roles' => $roles, 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]));
    }

    public function test_preferences_are_owned_and_templates_reject_unknown_variables(): void
    {
        $user = $this->user(['client']);
        $this->actingAs($user);
        $this->patchJson('/api/v1/notification-preferences', ['user_id' => 'someone', 'locale' => 'en', 'digest_hour' => 8, 'channels' => ['invoice' => 'daily']])->assertOk();
        $this->assertNull(app(RecordStore::class)->get('notification_preferences', 'someone'));
        $this->withSession(['auth.confirmed_at' => time()])->postJson('/api/v1/email-templates', ['key' => 'notification', 'locale' => 'en', 'subject' => 'Test', 'body' => '{{password}} {{action_url}}'])->assertForbidden();
        $this->actingAs($this->user())->postJson('/api/v1/email-templates', ['key' => 'notification', 'locale' => 'en', 'subject' => 'Test', 'body' => '{{password}} {{action_url}}'])->assertUnprocessable();
    }

    public function test_delivery_is_deduplicated_and_rechecks_preferences_and_verification(): void
    {
        $user = $this->user();
        $service = app(NotificationDelivery::class);
        $store = app(RecordStore::class);
        $service->savePreferences($user->id, ['locale' => 'en', 'digest_hour' => 8, 'channels' => ['document' => 'immediate']]);
        $job = app(Outbox::class)->enqueue('document.shared', ['user_id' => $user->id], 'example');
        app(JobRunner::class)->handle($job);
        app(JobRunner::class)->handle($job);
        $this->assertCount(1, $store->query('notifications'));
        $mail = $store->query('jobs', ['type' => 'notification.email']);
        $this->assertCount(1, $mail);
        $message = $service->message($mail[0]);
        $this->assertStringContainsString('/app/notifications', $message['body']);
        $this->assertArrayNotHasKey('document_title', $message);
        $service->savePreferences($user->id, ['locale' => 'en', 'digest_hour' => 8, 'channels' => ['document' => 'off']]);
        $this->assertNull($service->message($mail[0]));
    }

    public function test_daily_digest_uses_one_job_and_localized_approved_template(): void
    {
        $user = $this->user(['client']);
        $service = app(NotificationDelivery::class);
        $store = app(RecordStore::class);
        $service->savePreferences($user->id, ['locale' => 'fr', 'digest_hour' => 8, 'channels' => ['invoice' => 'daily']]);
        $service->saveTemplate(['key' => 'digest', 'locale' => 'fr', 'subject' => 'Votre espace', 'body' => '{{count}} mises à jour : {{action_url}}']);
        for ($n = 0; $n < 2; $n++) {
            $service->emit(app(Outbox::class)->enqueue('invoice.issued', ['user_id' => $user->id], (string) $n));
        }
        $this->assertCount(0, $store->query('jobs', ['type' => 'notification.email']));
        $this->travel(2)->days();
        $service->queueDue();
        $service->queueDue();
        $jobs = $store->query('jobs', ['type' => 'notification.email']);
        $this->assertCount(1, $jobs);
        $message = $service->message($jobs[0]);
        $this->assertSame('Votre espace', $message['subject']);
        $this->assertStringContainsString('2 mises à jour', $message['body']);
        $this->assertStringContainsString('/portal/notifications', $message['body']);
        $record = $store->get('users', $user->id);
        $store->put('users', $user->id, array_replace($record, ['email_verified_at' => null]), $record['version']);
        $this->assertNull($service->message($jobs[0]));
    }

    public function test_client_notifications_and_email_recheck_explicit_invoice_access(): void
    {
        $client = $this->user(['client']);
        $store = app(RecordStore::class);
        $delivery = app(NotificationDelivery::class);
        $delivery->savePreferences($client->id, ['locale' => 'en', 'digest_hour' => 8, 'channels' => ['invoice' => 'immediate']]);
        $invoice = $store->create('invoices', ['client_ids' => [$client->id], 'visibility' => 'shared', 'owner_id' => 'other']);
        $delivery->emit(['id' => 'invoice-event', 'type' => 'invoice.issued', 'payload' => ['invoice_id' => $invoice['id']]]);
        $jobs = $store->query('jobs', ['type' => 'notification.email']);
        $this->assertCount(1, $jobs);
        $this->assertStringContainsString('/portal/notifications', $delivery->message($jobs[0])['body']);
        $store->put('invoices', $invoice['id'], array_replace($invoice, ['client_ids' => []]), $invoice['version']);
        $this->assertNull($delivery->message($jobs[0]));
    }
}
