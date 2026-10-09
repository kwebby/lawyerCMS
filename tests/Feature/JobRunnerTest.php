<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Contracts\RecordStore;
use App\Support\Health;
use App\Support\JobRunner;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class JobRunnerTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(RecordStore::class);
        $this->vault = storage_path('framework/testing-vault-'.bin2hex(random_bytes(5)));
        config(['crm.private_path' => $this->vault]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    public function test_a_job_whose_last_attempt_died_is_failed_instead_of_reclaimed_forever(): void
    {
        $owner = $this->store->create('users', ['name' => 'Owner', 'email' => 'owner@example.test', 'roles' => ['owner'], 'status' => 'active']);
        $run = $this->store->create('ai_runs', ['context' => 'internal', 'status' => 'queued', 'owner_id' => $owner['id']]);
        $event = app(Outbox::class)->enqueue('record.changed', [], 'crashing-event');
        $ai = app(Outbox::class)->enqueue('ai.run', ['run_id' => $run['id']], $run['id']);
        $runner = app(JobRunner::class);
        for ($attempt = 1; $attempt <= 5; $attempt++) { // Each attempt dies (fatal error, out of memory, kill) before finish().
            $this->assertSame($attempt, $runner->claim($event['id'])['attempts']);
            $this->assertSame($attempt, $runner->claim($ai['id'])['attempts']);
            $this->travel(4)->minutes();
        }
        $this->assertSame(['done' => 0, 'failed' => 0], array_intersect_key($runner->tick(45), ['done' => 0, 'failed' => 0]));
        foreach ([$event, $ai] as $job) {
            $job = $this->store->get('jobs', $job['id']);
            $this->assertSame(['failed', 5, 'LeaseExpired', null], [$job['status'], $job['attempts'], $job['last_error'], $job['lease_token']]);
            $this->assertNull($runner->claim($job['id']));
        }
        $this->assertSame('failed', $this->store->get('ai_runs', $run['id'])['status']);
        $notifications = $this->store->query('notifications', ['user_id' => $owner['id']]);
        $this->assertSame(['A background job needs attention'], array_values(array_unique(array_column($notifications, 'title'))));
        $this->assertCount(2, $notifications);
    }

    public function test_a_persistently_failing_scheduling_step_does_not_stop_jobs_and_repeats_surface_in_health(): void
    {
        Log::spy();
        $broken = $this->store->create('recurring_invoices', ['status' => 'active', 'next_run_at' => 'not-a-date']);
        $runner = app(JobRunner::class);
        for ($tick = 1; $tick <= 3; $tick++) {
            $job = app(Outbox::class)->enqueue('record.changed', []);
            $this->assertSame(1, $runner->tick(45)['done']);
            $this->assertSame('completed', $this->store->get('jobs', $job['id'])['status']);
            $failure = $this->store->get('system', 'cron')['failures']['recurring_invoices'];
            $this->assertSame([$tick, 'InvalidFormatException'], [$failure['count'], $failure['error']]);
            $this->assertSame($tick < 3 ? 'healthy' : 'attention', app(Health::class)->report()['cron']['status']);
        }
        Log::shouldHaveReceived('warning')->with('crm:tick step failed', ['step' => 'recurring_invoices', 'error' => 'InvalidFormatException'])->times(3);
        $this->store->delete('recurring_invoices', $broken['id']);
        $runner->tick(45);
        $this->assertSame([], app(Health::class)->report()['cron']['failing_steps']);
        $this->assertSame('healthy', app(Health::class)->report()['cron']['status']);
    }

    public function test_old_finished_jobs_are_purged_in_bounded_batches_and_unfinished_or_recent_jobs_are_kept(): void
    {
        $job = fn (string $status, array $data = []) => $this->store->create('jobs', $data + ['type' => 'record.changed', 'payload' => [], 'status' => $status, 'attempts' => 1, 'available_at' => now()->toISOString(), 'lease_until' => null, 'lease_token' => null]);
        $this->travel(-100)->days();
        $oldFailed = $job('failed');
        $oldPending = $job('pending', ['available_at' => now()->addYears(2)->toISOString()]);
        $this->travel(60)->days();
        $recentFailed = $job('failed');
        $oldCompleted = [$job('completed'), $job('completed'), app(Outbox::class)->enqueue('publishing.published', [], 'publish-page-2')];
        $finished = $this->store->get('jobs', $oldCompleted[2]['id']);
        $this->store->put('jobs', $finished['id'], array_replace($finished, ['status' => 'completed']), $finished['version']);
        $this->travel(5)->days();
        $completedLate = $job('pending');
        $this->travelBack();
        $this->store->put('jobs', $completedLate['id'], array_replace($completedLate, ['status' => 'completed']), $completedLate['version']);
        $recentCompleted = $job('completed');
        $runner = app(JobRunner::class);
        $this->assertSame(2, $runner->purgeJobs(2));
        $this->assertCount(6, $this->store->query('jobs', [], 100));
        $runner->tick(45);
        $remaining = array_column($this->store->query('jobs', [], 100), 'id');
        sort($remaining);
        $expected = [$oldPending['id'], $recentFailed['id'], $completedLate['id'], $recentCompleted['id']];
        sort($expected);
        $this->assertSame($expected, $remaining);
        $this->assertSame('pending', app(Outbox::class)->enqueue('publishing.published', [], 'publish-page-2')['status'], 'A purged dedupe key is usable again.');
    }

    public function test_each_handler_runs_under_time_limits_shorter_than_its_lease(): void
    {
        config(['crm.scanner.url' => 'https://8.8.8.8/scan', 'crm.approved_hosts' => ['8.8.8.8']]);
        $document = $this->store->create('documents', ['name' => 'letter.txt', 'path' => app(PrivateFiles::class)->write('upload', 'quarantine'), 'status' => 'quarantined']);
        app(Outbox::class)->enqueue('scan', ['document_id' => $document['id']], $document['id']);
        $limits = null;
        Http::fake(function () use (&$limits) {
            $alarm = function_exists('pcntl_alarm') ? pcntl_alarm(0) : null;
            if ($alarm) {
                pcntl_alarm($alarm);
            }
            $limits = ['cpu' => (int) ini_get('max_execution_time'), 'alarm' => $alarm];

            return Http::response(['sha256' => hash('sha256', 'upload'), 'verdict' => 'clean']);
        });
        $before = ini_get('max_execution_time');
        $this->assertSame(1, app(JobRunner::class)->tick(45)['done']);
        $this->assertSame('clean', $this->store->get('documents', $document['id'])['status']);
        $this->assertGreaterThan(0, $limits['cpu']);
        $this->assertLessThan(180, $limits['cpu']);
        if (function_exists('pcntl_alarm')) {
            $this->assertGreaterThan(0, $limits['alarm']);
            $this->assertLessThan(180, $limits['alarm']);
            $this->assertSame(0, pcntl_alarm(0), 'The alarm must be cancelled once the handler returns.');
        }
        $this->assertSame($before, ini_get('max_execution_time'));
    }
}
