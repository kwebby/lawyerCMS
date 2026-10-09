<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Contracts\RecordStore;
use App\Support\JobRunner;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
