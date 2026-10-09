<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;
use App\Domain\Communications\NotificationDelivery;
use App\Domain\Finance\FinancialDocuments;
use App\Domain\Finance\PaymentService;
use App\Domain\Finance\RecurringInvoiceService;
use App\Domain\Publishing\IndexNow;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class JobRunner
{
    public function __construct(private RecordStore $store, private PrivateFiles $files, private UploadScanner $scanner, private AiGateway $ai, private Settings $settings) {}

    public function tick(int $budgetSeconds = 45): array
    {
        $started = microtime(true);
        $done = 0;
        $failed = 0;
        if (app()->isDownForMaintenance()) {
            return ['done' => 0, 'failed' => 0, 'maintenance' => true];
        }
        app(PaymentService::class)->queuePendingChecks();
        app(RecurringInvoiceService::class)->queueDue();
        app(NotificationDelivery::class)->queueDue();
        $this->store->put('system', 'cron', ['last_run_at' => now()->toISOString()]);
        $this->expireTemporary();
        $jobs = array_merge($this->store->query('jobs', ['status' => 'pending'], 100, 'available_at', 'asc'), $this->store->query('jobs', ['status' => 'running'], 100, 'lease_until', 'asc'));
        foreach ($jobs as $candidate) {
            if (microtime(true) - $started > $budgetSeconds - 25) {
                break;
            }
            $job = $this->claim($candidate['id']);
            if (! $job) {
                continue;
            }
            try {
                $this->handle($job);
                $this->finish($job);
                $done++;
            } catch (\Throwable $error) {
                $this->finish($job, $error);
                $failed++;
            }
        }

        return compact('done', 'failed');
    }

    public function claim(string $id): ?array
    {
        return $this->store->transaction(function () use ($id) {
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

            return $this->store->put('jobs', $id, array_merge($job, ['status' => 'running', 'attempts' => $job['attempts'] + 1, 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(3)->toISOString()]), $job['version']);
        });
    }

    private function finish(array $lease, ?\Throwable $error = null): void
    {
        $admins = [];
        if ($error && $lease['attempts'] >= 5) {
            foreach ($this->store->each('users') as $user) {
                if (array_intersect($user['roles'], ['owner', 'admin'])) {
                    $admins[] = $user['id'];
                }
            }
        }
        $this->store->transaction(function () use ($lease, $error, $admins) {
            $job = $this->store->get('jobs', $lease['id']);
            if (! $job || $job['lease_token'] !== $lease['lease_token']) {
                return;
            }
            $terminal = $error && $job['attempts'] >= 5;
            $status = $error ? ($terminal ? 'failed' : 'pending') : 'completed';
            $record = array_merge($job, ['status' => $status, 'lease_token' => null, 'lease_until' => null, 'completed_at' => $error ? null : now()->toISOString(), 'available_at' => now()->addSeconds(min(3600, 30 * 2 ** $job['attempts']))->toISOString(), 'last_error' => $error ? class_basename($error) : null]);
            $this->store->put('jobs', $job['id'], $record, $job['version']);
            if ($terminal && in_array($job['type'], ['ai.run', 'analyzer.scan', 'analyzer.run'])) {
                $run = $this->store->get('ai_runs', $job['payload']['run_id']);
                if ($run && ! in_array($run['status'], ['review', 'approved', 'rejected', 'expired'])) {
                    $this->store->put('ai_runs', $run['id'], array_merge($run, ['status' => 'failed', 'error' => 'Processing could not complete. Check source readability and integration health; an administrator can inspect the failed job.']), $run['version']);
                }
            }
            if ($terminal) {
                foreach ($admins as $userId) {
                    $this->store->create('notifications', ['user_id' => $userId, 'title' => 'A background job needs attention', 'category' => 'system', 'severity' => 'error', 'action_required' => true, 'read_at' => null, 'action_url' => '/app/settings', 'job_id' => $job['id']]);
                }
            }
        });
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
