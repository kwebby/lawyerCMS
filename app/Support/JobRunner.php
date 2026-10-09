<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;
use App\Domain\Communications\NotificationDelivery;
use App\Domain\Finance\FinancialDocuments;
use App\Domain\Finance\PaymentService;
use App\Domain\Finance\RecurringInvoiceService;
use App\Domain\Publishing\IndexNow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class JobRunner
{
    private const MAX_ATTEMPTS = 5;

    private const LEASE_SECONDS = 180;

    /** Below the lease, so a handler stopped by the limit leaves a lease that expires and is counted as a failed attempt. */
    private const HANDLER_SECONDS = 120;

    public function __construct(private RecordStore $store, private PrivateFiles $files, private UploadScanner $scanner, private AiGateway $ai, private Settings $settings) {}

    public function tick(int $budgetSeconds = 45): array
    {
        $started = microtime(true);
        $done = 0;
        $failed = 0;
        if (app()->isDownForMaintenance()) {
            return ['done' => 0, 'failed' => 0, 'maintenance' => true];
        }
        $this->prelude();
        $jobs = array_merge($this->store->query('jobs', ['status' => 'pending'], 100, 'available_at', 'asc'), $this->store->query('jobs', ['status' => 'running'], 100, 'lease_until', 'asc'));
        $cpu = (int) ini_get('max_execution_time');
        foreach ($jobs as $candidate) {
            if (microtime(true) - $started > $budgetSeconds - 25) {
                break;
            }
            $job = $this->claim($candidate['id']);
            if (! $job) {
                continue;
            }
            try {
                try {
                    $this->limit(self::HANDLER_SECONDS, self::HANDLER_SECONDS);
                    $this->handle($job);
                } finally {
                    $this->limit(0, $cpu);
                }
                $this->finish($job);
                $done++;
            } catch (\Throwable $error) {
                $this->finish($job, $error);
                $failed++;
            }
        }

        return compact('done', 'failed');
    }

    /** Each scheduling step is isolated so one persistent error cannot stop job processing; consecutive failures are kept on the heartbeat for Health. */
    private function prelude(): void
    {
        $failures = [];
        $previous = $this->step('heartbeat', fn () => $this->store->get('system', 'cron'), $failures)['failures'] ?? [];
        $this->step('payment_checks', fn () => app(PaymentService::class)->queuePendingChecks(), $failures, $previous);
        $this->step('recurring_invoices', fn () => app(RecurringInvoiceService::class)->queueDue(), $failures, $previous);
        $this->step('notification_digests', fn () => app(NotificationDelivery::class)->queueDue(), $failures, $previous);
        $this->step('temporary_expiry', fn () => $this->expireTemporary(), $failures, $previous);
        $this->step('job_retention', fn () => $this->purgeJobs(), $failures, $previous);
        $this->step('heartbeat', fn () => $this->store->put('system', 'cron', ['last_run_at' => now()->toISOString(), 'failures' => $failures]), $failures, $previous);
    }

    private function step(string $name, callable $step, array &$failures, array $previous = []): mixed
    {
        try {
            return $step();
        } catch (\Throwable $error) {
            // Exception messages can carry record data; log only the step and exception class.
            rescue(fn () => Log::warning('crm:tick step failed', ['step' => $name, 'error' => class_basename($error)]), report: false);
            $failures[$name] = ['error' => class_basename($error), 'count' => ($previous[$name]['count'] ?? 0) + 1, 'since' => $previous[$name]['since'] ?? now()->toISOString()];

            return null;
        }
    }

    /**
     * Delete completed jobs after 30 days and failed jobs after 90, at most $limit per call. A deduplicated job ID may be
     * reused after deletion: every dedupe key is either guarded by its own effect record (payment, lead, notification,
     * recurring occurrence, issued status, content version) or names an hour/day/period that does not recur.
     */
    public function purgeJobs(int $limit = 100, int $seconds = 5): int
    {
        $deadline = microtime(true) + $seconds;
        $deleted = 0;
        foreach (['completed' => 30, 'failed' => 90] as $status => $days) {
            $cutoff = now()->subDays($days)->toISOString();
            foreach ($this->store->query('jobs', ['status' => $status], $limit, 'created_at', 'asc') as $job) {
                if ($deleted >= $limit || microtime(true) > $deadline || $job['created_at'] > $cutoff) {
                    break;
                }
                if ($job['updated_at'] > $cutoff) {
                    continue;
                }
                try {
                    $this->store->delete('jobs', $job['id'], $job['version']);
                    $deleted++;
                } catch (Conflict) {
                }
            }
        }

        return $deleted;
    }

    public function claim(string $id): ?array
    {
        $peek = $this->store->get('jobs', $id);
        $admins = $peek && $this->exhausted($peek) ? $this->admins() : null; // each() cannot run inside the transaction.

        return $this->store->transaction(function () use ($id, $admins) {
            $job = $this->store->get('jobs', $id);
            if (! $job) {
                return null;
            }
            if ($job['status'] === 'running' && ($job['lease_until'] ?? '') > now()->toISOString()) {
                return null;
            }
            if (! in_array($job['status'], ['pending', 'running']) || $job['available_at'] > now()->toISOString()) {
                return null;
            }
            if ($this->exhausted($job)) { // The last attempt died without finishing (fatal error, timeout or kill).
                if ($admins !== null) {
                    $this->store->put('jobs', $id, array_merge($job, ['status' => 'failed', 'lease_token' => null, 'lease_until' => null, 'last_error' => 'LeaseExpired']), $job['version']);
                    $this->failed($job, $admins);
                }

                return null;
            }

            return $this->store->put('jobs', $id, array_merge($job, ['status' => 'running', 'attempts' => $job['attempts'] + 1, 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addSeconds(self::LEASE_SECONDS)->toISOString()]), $job['version']);
        });
    }

    private function exhausted(array $job): bool
    {
        return $job['status'] === 'running' && ($job['lease_until'] ?? '') <= now()->toISOString() && $job['attempts'] >= self::MAX_ATTEMPTS;
    }

    private function admins(): array
    {
        $admins = [];
        foreach ($this->store->each('users') as $user) {
            if (array_intersect($user['roles'], ['owner', 'admin'])) {
                $admins[] = $user['id'];
            }
        }

        return $admins;
    }

    /** CPU-time limit plus, where pcntl is available, a wall-clock alarm whose default action kills a hung tick; 0 seconds cancels the alarm. */
    private function limit(int $seconds, int $cpuSeconds): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit($cpuSeconds);
        }
        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
            if ($seconds) {
                pcntl_signal(SIGALRM, SIG_DFL);
            }
            pcntl_alarm($seconds);
        }
    }

    private function finish(array $lease, ?\Throwable $error = null): void
    {
        $admins = $error && $lease['attempts'] >= self::MAX_ATTEMPTS ? $this->admins() : [];
        $this->store->transaction(function () use ($lease, $error, $admins) {
            $job = $this->store->get('jobs', $lease['id']);
            if (! $job || $job['lease_token'] !== $lease['lease_token']) {
                return;
            }
            $terminal = $error && $job['attempts'] >= self::MAX_ATTEMPTS;
            $status = $error ? ($terminal ? 'failed' : 'pending') : 'completed';
            $record = array_merge($job, ['status' => $status, 'lease_token' => null, 'lease_until' => null, 'completed_at' => $error ? null : now()->toISOString(), 'available_at' => now()->addSeconds(min(3600, 30 * 2 ** $job['attempts']))->toISOString(), 'last_error' => $error ? class_basename($error) : null]);
            $this->store->put('jobs', $job['id'], $record, $job['version']);
            if ($terminal) {
                $this->failed($job, $admins);
            }
        });
    }

    /** Terminal-failure side effects; call inside the transaction that marks the job failed. */
    private function failed(array $job, array $admins): void
    {
        if (in_array($job['type'], ['ai.run', 'analyzer.scan', 'analyzer.run'])) {
            $run = $this->store->get('ai_runs', $job['payload']['run_id']);
            if ($run && ! in_array($run['status'], ['review', 'approved', 'rejected', 'expired'])) {
                $this->store->put('ai_runs', $run['id'], array_merge($run, ['status' => 'failed', 'error' => 'Processing could not complete. Check source readability and integration health; an administrator can inspect the failed job.']), $run['version']);
            }
        }
        foreach ($admins as $userId) {
            $this->store->create('notifications', ['user_id' => $userId, 'title' => 'A background job needs attention', 'category' => 'system', 'severity' => 'error', 'action_required' => true, 'read_at' => null, 'action_url' => '/app/settings', 'job_id' => $job['id']]);
        }
    }

    public function handle(array $job): void
    {
        $payload = $job['payload'];
        switch ($job['type']) {
            case 'scan':
                $this->scan('documents', $payload['document_id']);
                break;
            case 'ai.run':
                $this->ai->run($payload['run_id']);
                break;
            case 'analyzer.run': // Old queued jobs also use the staged flow; missing consent fails closed.
            case 'analyzer.scan':
                $this->scanAnalyzer($payload['run_id']);
                break;
            case 'invoice.issued':
                $invoice = $this->store->get('invoices', $payload['invoice_id']);
                if (! $invoice) {
                    throw new \RuntimeException('Issued invoice is unavailable.');
                }
                app(FinancialDocuments::class)->invoice($invoice);
                $this->event($job);
                break;
            case 'payslip.released':
                $slip = $this->store->get('payslips', $payload['payslip_id']);
                if (! $slip) {
                    throw new \RuntimeException('Released payslip is unavailable.');
                }
                app(FinancialDocuments::class)->payslip($slip);
                $this->event($job);
                break;
            case 'publishing.published':
                app(IndexNow::class)->submit($payload['page_id']);
                break;
            case 'billing.recurring':
                app(RecurringInvoiceService::class)->generate($payload['schedule_id'], $payload['period']);
                break;
            case 'payment.reconcile':
                app(PaymentService::class)->reconcilePendingCheckout($payload['checkout_id']);
                break;
            case 'notification.email':
                $message = app(NotificationDelivery::class)->message($job);
                if ($message) {
                    $this->sendEmail($message, $job['id']);
                }
                break;
            case 'email':
                $this->sendEmail($payload, $job['id']);
                break;
            default:
                if (! preg_match('/^[a-z_]+\.[a-z_]+$/D', $job['type'])) {
                    throw new \RuntimeException('No job handler for this type.');
                }
                $this->event($job);
                break;
        }
    }

    private function scanAnalyzer(string $id): void
    {
        $run = $this->store->get('ai_runs', $id);
        abort_unless($run && $run['context'] === 'public' && $run['expires_at'] > now()->toISOString(), 410);
        if (in_array($run['status'], ['review', 'approved', 'rejected', 'expired', 'failed'])) {
            return;
        }
        $this->ai->assertPublicPolicy($run);
        $upload = $this->ai->publicUpload($run, false);
        if (($run['stage'] ?? '') === 'analysis') {
            return;
        } // Transition and outbox were committed together.
        if (($run['stage'] ?? '') !== 'scan' || $upload['status'] !== 'quarantined') {
            throw new Conflict('The upload is not awaiting scanning.');
        }
        $bytes = $this->files->read($upload['path']);
        if (! hash_equals($upload['sha256'], hash('sha256', $bytes))) {
            throw new Conflict('The uploaded file changed before scanning.');
        }
        // This stage performs one bounded external operation. AI runs in its own leased job.
        $clean = $this->scanner->scan($bytes, $upload['name']);
        $this->store->transaction(function () use ($id, $run, $upload, $clean) {
            $current = $this->store->get('ai_runs', $id);
            if (! $current || $current['expires_at'] <= now()->toISOString()) {
                throw new Conflict('Analysis expired during scanning.');
            }
            $this->ai->assertPublicPolicy($current);
            if (($current['stage'] ?? '') === 'analysis') {
                $this->ai->publicUpload($current);

                return;
            }
            if ($current['version'] !== $run['version']) {
                throw new Conflict('Analysis changed during scanning.');
            }
            $this->ai->publicUpload($current, false);
            $scanned = $this->store->put('analyzer_uploads', $upload['id'], array_merge($upload, ['status' => $clean ? 'clean' : 'rejected', 'scanned_at' => now()->toISOString()]), $upload['version']);
            $next = array_merge($current, ['upload_version' => $scanned['version'], 'status' => $clean ? 'queued' : 'failed', 'stage' => $clean ? 'analysis' : 'scan', 'error' => $clean ? null : 'The uploaded file was rejected by the security scanner.']);
            $this->store->put('ai_runs', $id, $next, $current['version']);
            if ($clean) {
                app(Outbox::class)->enqueue('ai.run', ['run_id' => $id], $id);
            }
        });
    }

    private function scan(string $collection, string $id): void
    {
        $document = $this->store->get($collection, $id);
        if (! $document) {
            return;
        }
        if (($document['expires_at'] ?? '9999') < now()->toISOString()) {
            throw new \RuntimeException('Upload expired.');
        }
        if ($document['status'] === 'clean') {
            return;
        }
        if ($document['status'] === 'rejected') {
            throw new \RuntimeException('Upload rejected by scanner.');
        }
        $clean = $this->scanner->scan($this->files->read($document['path']), $document['name']);
        $this->store->put($collection, $id, array_merge($document, ['status' => $clean ? 'clean' : 'rejected', 'scanned_at' => now()->toISOString()]), $document['version']);
        if (! $clean) {
            throw new \RuntimeException('Upload rejected by scanner.');
        }
    }

    private function sendEmail(array $payload, string $id): void
    {
        $settings = $this->settings->get('mail', true);
        if (! isset($settings['host'])) {
            throw new \RuntimeException('Configure SMTP before sending mail.');
        }
        $ip = app(EndpointPolicy::class)->smtp($settings['host'], (int) $settings['port']);
        config(['mail.mailers.crm' => ['transport' => 'smtp', 'scheme' => $settings['port'] === 465 ? 'smtps' : 'smtp', 'host' => $ip, 'port' => $settings['port'], 'username' => $settings['username'], 'password' => $settings['password'] ?? '', 'timeout' => 15, 'require_tls' => true, 'local_domain' => parse_url(config('app.url'), PHP_URL_HOST), 'stream' => ['ssl' => ['peer_name' => $settings['host'], 'verify_peer' => true, 'verify_peer_name' => true]]]]);
        Mail::purge('crm');
        $mailer = Mail::mailer('crm');
        $transport = $mailer->getSymfonyTransport();
        if ($transport instanceof EsmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setStreamOptions(['ssl' => ['peer_name' => $settings['host'], 'verify_peer' => true, 'verify_peer_name' => true]]);
            }
        }
        $mailer->raw($payload['body'], function ($message) use ($payload, $settings, $id) {
            $message->to($payload['to'])->from($settings['from_address'], $settings['from_name'])->subject($payload['subject']);
            if (! empty($settings['reply_to'])) {
                $message->replyTo($settings['reply_to']);
            }
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-LawyerCMS-Delivery', $id);
        });
        $this->store->create('email_deliveries', ['job_id' => $id, 'recipient_hash' => hash('sha256', $payload['to']), 'status' => 'accepted', 'accepted_at' => now()->toISOString()]);
    }

    private function event(array $job): void
    {
        app(NotificationDelivery::class)->emit($job);
    }

    public function expireTemporary(int $seconds = 5): void
    {
        $deadline = microtime(true) + $seconds;
        foreach (['analyzer_uploads', 'ai_runs', 'analyzer_grants'] as $collection) {
            foreach ($this->store->query($collection, $collection === 'ai_runs' ? ['context' => 'public'] : [], 25, 'expires_at', 'asc') as $record) {
                if (microtime(true) > $deadline) {
                    return;
                }
                if (! isset($record['expires_at']) || $record['expires_at'] > now()->toISOString()) {
                    continue;
                }
                foreach (['path', 'result_path'] as $key) {
                    if (isset($record[$key])) {
                        $this->files->delete($record[$key]);
                    }
                }
                $this->store->delete($collection, $record['id'], $record['version']);
            }
        }
    }
}
