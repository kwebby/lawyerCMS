<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Communications;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class NotificationDelivery
{
    public const CATEGORIES = ['security', 'system', 'record', 'matter', 'review', 'task', 'document', 'message', 'invoice', 'payment', 'payroll', 'payslip', 'employee', 'publishing', 'ai'];

    private const DEFAULTS = [
        'notification' => ['subject' => 'An update from {{firm_name}}', 'body' => "There is an update in your secure workspace.\n\nSign in to view it: {{action_url}}"],
        'digest' => ['subject' => 'Your {{firm_name}} workspace digest', 'body' => "You have {{count}} new workspace updates.\n\nSign in to view them: {{action_url}}"],
    ];

    public function __construct(private RecordStore $store, private Outbox $outbox, private Settings $settings) {}

    public function preferences(string $userId): array
    {
        return $this->store->get('notification_preferences', $userId) ?? ['locale' => 'en', 'channels' => [], 'digest_hour' => 8];
    }

    public function savePreferences(string $userId, array $input): array
    {
        $data = Validator::make($input, ['locale' => ['required', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/D'], 'digest_hour' => 'required|integer|min:0|max:23', 'channels' => 'present|array|max:30', 'channels.*' => ['required', Rule::in(['off', 'immediate', 'daily'])]])->validate();
        foreach (array_keys($data['channels']) as $category) {
            if (! in_array($category, self::CATEGORIES, true)) {
                throw ValidationException::withMessages(['channels' => 'Unknown notification category.']);
            }
        }

        return $this->store->put('notification_preferences', $userId, $data);
    }

    public function templates(): array
    {
        return ['defaults' => self::DEFAULTS, 'overrides' => $this->store->query('email_templates', [], 200), 'variables' => ['firm_name', 'action_url', 'count']];
    }

    public function saveTemplate(array $input): array
    {
        $data = Validator::make($input, ['key' => ['required', Rule::in(array_keys(self::DEFAULTS))], 'locale' => ['required', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/D'], 'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'], 'body' => 'required|string|max:5000'])->validate();
        foreach (['subject', 'body'] as $field) {
            preg_match_all('/{{\s*([^}]+)\s*}}/', $data[$field], $matches);
            foreach ($matches[1] as $variable) {
                if (! in_array(trim($variable), ['firm_name', 'action_url', 'count'], true)) {
                    throw ValidationException::withMessages([$field => 'Only approved variables may be used.']);
                }
            }
            if (preg_match('/[{}]/', preg_replace('/{{\s*(firm_name|action_url|count)\s*}}/', '', $data[$field]))) {
                throw ValidationException::withMessages([$field => 'Invalid template delimiters.']);
            }
        }
        if (! str_contains($data['body'], '{{action_url}}')) {
            throw ValidationException::withMessages(['body' => 'Include {{action_url}} so recipients can open the secure workspace.']);
        }

        return $this->store->put('email_templates', $data['key'].'.'.$data['locale'], $data);
    }

    public function emit(array $job): void
    {
        $payload = $job['payload'];
        $resource = null;
        foreach (['invoice_id' => 'invoices', 'matter_id' => 'matters', 'document_id' => 'documents', 'conversation_id' => 'conversations'] as $field => $collection) {
            if (! empty($payload[$field])) {
                $resource = ['collection' => $collection, 'id' => $payload[$field]];
                break;
            }
        }
        if (isset($payload['collection'],$payload['id']) && in_array($payload['collection'], ['leads', 'contacts', 'matters', 'tasks', 'proceedings'], true)) {
            $resource = ['collection' => $payload['collection'], 'id' => $payload['id']];
        }

        $targets = $payload['user_ids'] ?? [];
        foreach (['user_id', 'employee_id'] as $key) {
            if (isset($payload[$key])) {
                $targets[] = $payload[$key];
            }
        }
        if (isset($payload['actor_id'])) {
            $targets[] = $payload['actor_id'];
        }
        // Resolve current assignees; never copy matter or message text into email.
        if (isset($payload['collection'],$payload['id']) && in_array($payload['collection'], ['leads', 'contacts', 'matters', 'tasks', 'proceedings'], true)) {
            $record = $this->store->get($payload['collection'], $payload['id']);
            $targets = array_merge($targets, [$record['owner_id'] ?? null], $record['team_ids'] ?? []);
        }
        if ($resource && $resource['collection'] === 'invoices') {
            $invoice = $this->store->get('invoices', $resource['id']);
            $targets = array_merge($targets, $invoice['client_ids'] ?? [], [$invoice['owner_id'] ?? null]);
        }
        foreach ($this->store->query('users', [], 500) as $user) {
            if (! str_starts_with($job['type'], 'message.') && (array_intersect($user['roles'], ['owner', 'admin']) || (str_starts_with($job['type'], 'payment.') && in_array('accounts', $user['roles'], true)))) {
                $targets[] = $user['id'];
            }
        }
        foreach (array_unique(array_filter($targets)) as $userId) {
            $this->store->transaction(function () use ($job, $userId, $resource) {
                $user = $this->store->get('users', $userId);
                if (! $user || ($user['status'] ?? 'active') !== 'active') {
                    return;
                }
                if ($resource) {
                    $source = $this->store->get($resource['collection'], $resource['id']);
                    if (! $source || ! app(Access::class)->can(new CrmUser($user), $resource['collection'].'.read', $source)) {
                        return;
                    }
                }
                $id = hash('sha256', $job['id'].':'.$userId);
                if ($this->store->get('notifications', $id)) {
                    return;
                }
                $category = explode('.', $job['type'])[0];
                if (! in_array($category, self::CATEGORIES, true)) {
                    $category = 'system';
                }
                $client = (bool) array_intersect($user['roles'], ['client', 'prospect']);
                $section = $resource ? (['conversations' => 'chat', 'invoices' => 'invoices', 'matters' => 'matters', 'documents' => 'documents', 'leads' => 'leads', 'contacts' => 'contacts', 'tasks' => 'tasks', 'proceedings' => 'matters'][$resource['collection']]) : 'notifications';
                $this->store->create('notifications', ['user_id' => $userId, 'title' => ucfirst(str_replace(['.', '_'], ' ', $job['type'])), 'category' => $category, 'severity' => 'info', 'read_at' => null, 'action_required' => str_contains($job['type'], 'review'), 'action_url' => ($client ? '/portal' : '/app').'/'.$section, 'resource' => $resource], $id);
                $preference = $this->preferences($userId);
                $channel = $preference['channels'][$category] ?? 'off';
                if (! $user['email_verified_at'] || $channel === 'off') {
                    return;
                }
                if ($channel === 'immediate') {
                    $this->outbox->enqueue('notification.email', ['user_id' => $userId, 'notification_ids' => [$id], 'template' => 'notification', 'category' => $category], 'notification-'.$id);
                } else {
                    $this->store->create('notification_digest_items', ['user_id' => $userId, 'notification_id' => $id, 'category' => $category, 'status' => 'pending', 'available_at' => now()->startOfDay()->addDay()->setHour($preference['digest_hour'])->toISOString()], $id);
                }
            });
        }
    }

    public function queueDue(int $limit = 25): int
    {
        $items = $this->store->query('notification_digest_items', ['status' => 'pending'], min(25, $limit), 'available_at', 'asc');
        $count = 0;
        foreach ($items as $item) {
            if ($item['available_at'] > now()->toISOString()) {
                break;
            }
            $this->store->transaction(function () use ($item) {
                $current = $this->store->get('notification_digest_items', $item['id']);
                if ($current['status'] !== 'pending') {
                    return;
                }
                // One daily bucket per recipient; repeated cron invocations share a durable job.
                $day = substr($current['available_at'], 0, 10);
                $job = $this->outbox->enqueue('notification.email', ['user_id' => $item['user_id'], 'template' => 'digest', 'digest_day' => $day], $item['user_id'].':'.$day);
                if ($job['status'] === 'completed') { // Late items enter the next digest rather than being lost.
                    $this->store->put('notification_digest_items', $item['id'], array_replace($current, ['available_at' => now()->addDay()->toISOString()]), $current['version']);

                    return;
                }
                $this->store->put('notification_digest_items', $item['id'], array_replace($current, ['status' => 'queued', 'job_id' => $job['id']]), $current['version']);
            });
            $count++;
        }

        return $count;
    }

    public function message(array $job): ?array
    {
        $payload = $job['payload'];
        $user = $this->store->get('users', $payload['user_id']);
        if (! $user || ($user['status'] ?? 'active') !== 'active' || empty($user['email_verified_at']) || (! empty($user['access_expires_at']) && $user['access_expires_at'] <= now()->toISOString())) {
            return null;
        }
        $prefs = $this->preferences($user['id']);
        $digest = $payload['template'] === 'digest';
        $ids = $digest ? array_column($this->store->query('notification_digest_items', ['job_id' => $job['id']], 500), 'notification_id') : ($payload['notification_ids'] ?? []);
        $count = 0;
        foreach ($ids as $id) {
            $notification = $this->store->get('notifications', $id);
            if ($notification && ! empty($notification['resource'])) {
                $resource = $notification['resource'];
                $source = $this->store->get($resource['collection'], $resource['id']);
                if (! $source || ! app(Access::class)->can(new CrmUser($user), $resource['collection'].'.read', $source)) {
                    continue;
                }
            }
            if ($notification && $notification['user_id'] === $user['id'] && empty($notification['read_at']) && ($prefs['channels'][$notification['category']] ?? 'off') === ($digest ? 'daily' : 'immediate')) {
                $count++;
            }
        }
        if (! $count) {
            return null;
        }
        $key = $payload['template'];
        $template = $this->store->get('email_templates', $key.'.'.$prefs['locale']) ?? $this->store->get('email_templates', $key.'.en') ?? self::DEFAULTS[$key];
        $business = $this->settings->get('business');
        $client = (bool) array_intersect($user['roles'], ['client', 'prospect']);
        $variables = ['firm_name' => $business['trading_name'] ?? $business['legal_name'] ?? 'LawyerCMS', 'count' => (string) $count, 'action_url' => rtrim(config('app.url'), '/').($client ? '/portal' : '/app').'/notifications'];
        $render = fn ($text) => preg_replace_callback('/{{\s*(firm_name|action_url|count)\s*}}/', fn ($match) => $variables[$match[1]], $text);

        return ['to' => $user['email'], 'subject' => $render($template['subject']), 'body' => $render($template['body'])];
    }
}
