<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;

final class Health
{
    public function __construct(private RecordStore $store, private Settings $settings) {}

    public function report(): array
    {
        $started = microtime(true);
        $heartbeat = $this->store->get('system', 'cron');
        $latency = round((microtime(true) - $started) * 1000, 1);
        $pending = $this->store->query('jobs', ['status' => 'pending'], 1000, 'created_at', 'asc');
        $failed = $this->store->query('jobs', ['status' => 'failed'], 1000);
        $path = config('crm.private_path');
        $free = is_dir($path) ? disk_free_space($path) : false;
        $mail = $this->settings->get('mail');
        $ai = $this->settings->get('ai');
        $delivery = $this->store->query('email_deliveries', [], 1);
        $latestRun = $this->store->query('ai_runs', [], 1);
        $failingSteps = $heartbeat['failures'] ?? [];
        $repeated = array_filter($failingSteps, fn ($failure) => ($failure['count'] ?? 0) >= 3);

        return [
            'database' => ['status' => 'connected', 'profile' => config('crm.store') === 'firestore' ? 'firestore' : config('database.default'), 'latency_ms' => $latency],
            'cron' => ['status' => $heartbeat && strtotime($heartbeat['last_run_at']) > time() - 180 && ! $repeated ? 'healthy' : 'attention', 'last_run_at' => $heartbeat['last_run_at'] ?? null, 'failing_steps' => $failingSteps],
            'scanner' => ['status' => config('crm.scanner.url') ? 'configured' : 'not_configured', 'note' => 'Every upload requires a clean digest-matched response. Configuration alone does not establish scanner health.'],
            'private_storage' => ['status' => is_dir($path) && is_writable($path) && ($free === false || $free > 512 * 1024 * 1024) ? 'writable' : 'attention', 'free_bytes' => $free === false ? null : $free],
            'jobs' => ['pending' => count($pending), 'failed' => count($failed), 'counts_capped_at' => 1000, 'oldest_pending_at' => $pending[0]['created_at'] ?? null, 'oldest_pending_seconds' => isset($pending[0]) ? max(0, time() - strtotime($pending[0]['created_at'])) : 0],
            'smtp' => ['status' => ! empty($mail['host']) ? 'configured' : 'not_configured', 'last_accepted_at' => $delivery[0]['accepted_at'] ?? null],
            'ai' => ['status' => ($ai['enabled'] ?? false) ? 'configured' : 'disabled', 'last_run_status' => $latestRun[0]['status'] ?? null],
            'payments' => ['stripe' => config('services.stripe.secret') && config('services.stripe.webhook_secret') ? 'configured' : 'not_configured', 'paypal' => config('services.paypal.client_id') && config('services.paypal.secret') && config('services.paypal.webhook_id') ? 'configured' : 'not_configured'],
            'php' => ['version' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'), 'supported' => version_compare(PHP_VERSION, '8.3.0', '>=')],
        ];
    }
}
