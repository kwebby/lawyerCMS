<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Contracts\RecordStore;
use App\Support\AiGateway;
use App\Support\Conflict;
use App\Support\JobRunner;
use App\Support\LegalAssistant;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PublicAiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private string $vault;

    private const SOURCE = 'The notice asks the recipient to respond on 20 October. Ask a lawyer to verify the applicable date.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->store = app(RecordStore::class);
        $this->vault = storage_path('framework/testing-public-ai-'.bin2hex(random_bytes(5)));
        config([
            'app.url' => 'http://localhost',
            'crm.private_path' => $this->vault,
            'crm.scanner.url' => 'https://8.8.8.8/scan',
            'crm.scanner.key' => 'scanner-test-key',
            'crm.approved_hosts' => ['8.8.8.8'],
            'crm.ai_daily_limit' => 30,
            'services.turnstile.secret' => 'turnstile-test-key',
            'services.turnstile.site_key' => 'turnstile-site-key',
        ]);
        app(Settings::class)->save('ai', [
            'enabled' => true, 'public_tools_approved' => true, 'provider' => 'openai',
            'model' => 'test-model', 'api_key' => 'private-test-key', 'daily_limit' => 20,
        ]);
        LegalAssistant::fake(['Preliminary summary. Source: Uploaded document. Verify with a lawyer.']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    private function grant(): array
    {
        return $this->store->create('analyzer_grants', [
            'tool' => 'notice-explainer', 'email' => 'visitor@example.test', 'name' => 'Visitor',
            'jurisdiction' => 'Example jurisdiction', 'marketing_consent' => false,
            'consent_version' => 'public-analysis-v2', 'processing_policy' => app(AiGateway::class)->policyFingerprint(),
            'verified_at' => now()->toISOString(), 'expires_at' => now()->addDay()->toISOString(), 'used_at' => null,
        ]);
    }

    private function upload(?array $grant = null): array
    {
        $grant ??= $this->grant();
        $this->withSession(['analyzer_grant' => $grant['id']])->post('/tools/upload', [
            'file' => UploadedFile::fake()->createWithContent('notice.txt', self::SOURCE),
        ])->assertRedirect();
        $run = $this->store->query('ai_runs', ['grant_id' => $grant['id']])[0];
        $this->assertSame('scan', $run['stage']);

        return $run;
    }

    private function scanner(string $verdict = 'clean'): void
    {
        Http::fake(['https://8.8.8.8/scan' => Http::response(['verdict' => $verdict, 'sha256' => hash('sha256', self::SOURCE)])]);
    }

    private function scan(array $run): array
    {
        app(JobRunner::class)->handle(['type' => 'analyzer.scan', 'payload' => ['run_id' => $run['id']]]);

        return $this->store->get('ai_runs', $run['id']);
    }

    private function assertConflict(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the changed policy/source to stop processing.');
        } catch (Conflict $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_disclosure_fingerprint_binds_processor_and_key_without_exposing_secrets(): void
    {
        $ai = app(AiGateway::class);
        $first = $ai->policyFingerprint();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $page = $this->get('/tools/notice-explainer')->assertOk()->assertSee('Openai');
        $page->assertSee($first)->assertDontSee('private-test-key')->assertDontSee('scanner-test-key');
        app(Settings::class)->save('ai', ['daily_limit' => 1, 'api_key' => 'private-test-key']);
        $this->assertSame($first, $ai->policyFingerprint());
        foreach (['model' => 'changed-model', 'api_key' => 'rotated-key', 'provider' => 'anthropic', 'endpoint' => 'https://example.test/api'] as $field => $value) {
            $before = $ai->policyFingerprint();
            app(Settings::class)->save('ai', [$field => $value]);
            $this->assertNotSame($before, $ai->policyFingerprint(), $field);
        }
        $before = $ai->policyFingerprint();
        config(['ai.providers.anthropic.url' => 'https://new.example.test/api']);
        $this->assertNotSame($before, $ai->policyFingerprint());
    }

    public function test_email_request_rejects_missing_and_stale_disclosure_before_external_verification(): void
    {
        $input = ['email' => 'visitor@example.test', 'name' => 'Visitor', 'jurisdiction' => 'Example', 'processing_consent' => '1', 'cf-turnstile-response' => 'test'];
        $this->postJson('/tools/notice-explainer', $input)->assertUnprocessable()->assertJsonValidationErrors('processing_policy');
        $input['processing_policy'] = app(AiGateway::class)->policyFingerprint();
        app(Settings::class)->save('ai', ['model' => 'changed-model']);
        $this->postJson('/tools/notice-explainer', $input)->assertConflict();
        Http::assertNothingSent();
        $this->assertCount(0, $this->store->query('analyzer_grants'));
    }

    public function test_email_request_records_exact_disclosure_and_optional_marketing_is_not_required(): void
    {
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'localhost'])]);
        $policy = app(AiGateway::class)->policyFingerprint();
        $this->post('/tools/notice-explainer', ['email' => 'visitor@example.test', 'name' => 'Visitor', 'jurisdiction' => 'Example', 'processing_consent' => '1', 'processing_policy' => $policy, 'cf-turnstile-response' => 'test'])->assertOk();
        $grant = $this->store->query('analyzer_grants')[0];
        $this->assertSame($policy, $grant['processing_policy']);
        $this->assertFalse($grant['marketing_consent']);
        $this->assertNull($grant['verified_at']);
        $this->assertCount(1, $this->store->query('jobs', ['type' => 'email']));
    }

    public function test_upload_rejects_a_changed_provider_before_storing_private_bytes(): void
    {
        $grant = $this->grant();
        app(Settings::class)->save('ai', ['provider' => 'anthropic']);
        $this->withSession(['analyzer_grant' => $grant['id']])->postJson('/tools/upload', [
            'file' => UploadedFile::fake()->createWithContent('notice.txt', self::SOURCE),
        ])->assertConflict();
        $this->assertCount(0, $this->store->query('analyzer_uploads'));
        $this->assertCount(0, $this->store->query('ai_runs'));
        $this->assertDirectoryDoesNotExist($this->vault);
    }

    public function test_public_upload_honors_both_practice_and_server_daily_limits(): void
    {
        foreach ([[30, 1], [1, 30]] as [$serverLimit, $practiceLimit]) {
            config(['crm.ai_daily_limit' => $serverLimit]);
            app(Settings::class)->save('ai', ['daily_limit' => $practiceLimit]);
            $grant = $this->grant();
            $key = 'ai-'.now()->format('Y-m-d');
            $this->store->put('budgets', $key, ['used' => 1]);
            $this->withSession(['analyzer_grant' => $grant['id']])->postJson('/tools/upload', [
                'file' => UploadedFile::fake()->createWithContent('notice.txt', self::SOURCE),
            ])->assertTooManyRequests();
            $this->assertNull($this->store->get('analyzer_grants', $grant['id'])['used_at']);
            $this->assertSame(1, $this->store->get('budgets', $key)['used']);
        }
        $this->assertCount(0, $this->store->query('analyzer_uploads'));
        $this->assertCount(0, $this->store->query('ai_runs'));
        $this->assertCount(0, File::allFiles($this->vault));
    }

    public function test_scan_and_analysis_are_separate_idempotent_stages_with_versioned_sources(): void
    {
        $run = $this->upload();
        $this->assertCount(1, $this->store->query('jobs', ['type' => 'analyzer.scan']));
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'ai.run']));
        $this->assertConflict(fn () => app(AiGateway::class)->run($run['id']));
        LegalAssistant::assertNeverPrompted();
        $this->scanner();
        $scanned = $this->scan($run);
        $this->assertSame('analysis', $scanned['stage']);
        $this->assertSame(2, $scanned['upload_version']);
        $this->assertSame('clean', $this->store->get('analyzer_uploads', $run['upload_id'])['status']);
        $this->assertCount(1, $this->store->query('jobs', ['type' => 'ai.run']));
        LegalAssistant::assertNeverPrompted();
        $this->scan($run);
        Http::assertSentCount(1);
        $this->assertCount(1, $this->store->query('jobs', ['type' => 'ai.run']));
        app(AiGateway::class)->run($run['id']);
        app(AiGateway::class)->run($run['id']);
        LegalAssistant::assertPromptedTimes(1);
        $completed = $this->store->get('ai_runs', $run['id']);
        $this->assertSame('review', $completed['status']);
        $this->assertStringStartsWith('temporary/', $completed['result_path']);
        $this->assertCount(1, $this->store->query('jobs', ['type' => 'email']));
        $this->assertCount(0, $this->store->query('leads'));
        $this->withSession(['analyzer_grant' => 'different-visitor'])->get('/tools/results/'.$run['id'])->assertForbidden();
    }

    public function test_unavailable_or_infected_scanner_never_creates_an_ai_job(): void
    {
        $run = $this->upload();
        Http::fake(['https://8.8.8.8/scan' => Http::sequence()->push([], 503)->push(['verdict' => 'infected', 'sha256' => hash('sha256', self::SOURCE)])]);
        try {
            $this->scan($run);
            $this->fail('An unavailable scanner must retain quarantine.');
        } catch (RequestException) {
        }
        $this->assertSame('quarantined', $this->store->get('analyzer_uploads', $run['upload_id'])['status']);
        $this->assertSame('scan', $this->store->get('ai_runs', $run['id'])['stage']);
        $this->scan($run);
        $this->assertSame('rejected', $this->store->get('analyzer_uploads', $run['upload_id'])['status']);
        $this->assertSame('failed', $this->store->get('ai_runs', $run['id'])['status']);
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'ai.run']));
        LegalAssistant::assertNeverPrompted();
    }

    public function test_policy_change_while_scanning_keeps_the_source_quarantined_and_rolls_back_the_transition(): void
    {
        $run = $this->upload();
        Http::fake(['https://8.8.8.8/scan' => function () {
            app(Settings::class)->save('ai', ['model' => 'replacement-model']);

            return Http::response(['verdict' => 'clean', 'sha256' => hash('sha256', self::SOURCE)]);
        }]);
        $this->assertConflict(fn () => $this->scan($run));
        $this->assertSame('quarantined', $this->store->get('analyzer_uploads', $run['upload_id'])['status']);
        $this->assertSame('scan', $this->store->get('ai_runs', $run['id'])['stage']);
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'ai.run']));
    }

    public function test_changed_source_or_policy_after_scan_cannot_reach_ai(): void
    {
        $this->scanner();
        $run = $this->scan($this->upload());
        $upload = $this->store->get('analyzer_uploads', $run['upload_id']);
        $this->store->put('analyzer_uploads', $upload['id'], array_merge($upload, ['name' => 'changed.txt']), $upload['version']);
        $this->assertConflict(fn () => app(AiGateway::class)->run($run['id']));
        $otherRun = $this->scan($this->upload());
        app(Settings::class)->save('ai', ['api_key' => 'rotated-key']);
        $this->assertConflict(fn () => app(AiGateway::class)->run($otherRun['id']));
        LegalAssistant::assertNeverPrompted();
    }

    public function test_changed_source_during_ai_processing_cannot_release_a_result(): void
    {
        $this->scanner();
        $run = $this->scan($this->upload());
        LegalAssistant::fake(function () use ($run) {
            $upload = $this->store->get('analyzer_uploads', $run['upload_id']);
            $this->store->put('analyzer_uploads', $upload['id'], array_merge($upload, ['name' => 'changed.txt']), $upload['version']);

            return 'Output from an obsolete source.';
        });
        $this->assertConflict(fn () => app(AiGateway::class)->run($run['id']));
        $this->assertArrayNotHasKey('result_path', $this->store->get('ai_runs', $run['id']));
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'email']));
        $this->assertCount(1, File::allFiles($this->vault));
    }

    public function test_changed_policy_during_ai_processing_cannot_release_a_result(): void
    {
        $this->scanner();
        $run = $this->scan($this->upload());
        LegalAssistant::fake(function () {
            app(Settings::class)->save('ai', ['public_tools_approved' => false]);

            return 'Output after approval revocation.';
        });
        $this->assertConflict(fn () => app(AiGateway::class)->run($run['id']));
        $this->assertArrayNotHasKey('result_path', $this->store->get('ai_runs', $run['id']));
        $this->assertCount(1, File::allFiles($this->vault));
    }

    public function test_cron_leases_each_external_stage_independently(): void
    {
        $run = $this->upload();
        $this->scanner();
        $first = app(JobRunner::class)->tick(45);
        $this->assertSame(['done' => 1, 'failed' => 0], $first);
        $this->assertSame('completed', $this->store->query('jobs', ['type' => 'analyzer.scan'])[0]['status']);
        $this->assertSame('pending', $this->store->query('jobs', ['type' => 'ai.run'])[0]['status']);
        LegalAssistant::assertNeverPrompted();
        $second = app(JobRunner::class)->tick(45);
        $this->assertSame(['done' => 1, 'failed' => 0], $second);
        $this->assertSame('review', $this->store->get('ai_runs', $run['id'])['status']);
        LegalAssistant::assertPromptedTimes(1);
    }

    public function test_changed_upload_during_scan_cannot_advance_the_source_version(): void
    {
        $run = $this->upload();
        Http::fake(['https://8.8.8.8/scan' => function () use ($run) {
            $upload = $this->store->get('analyzer_uploads', $run['upload_id']);
            $this->store->put('analyzer_uploads', $upload['id'], array_merge($upload, ['name' => 'replacement.txt']), $upload['version']);

            return Http::response(['verdict' => 'clean', 'sha256' => hash('sha256', self::SOURCE)]);
        }]);
        $this->assertConflict(fn () => $this->scan($run));
        $this->assertSame('quarantined', $this->store->get('analyzer_uploads', $run['upload_id'])['status']);
        $this->assertSame(1, $this->store->get('ai_runs', $run['id'])['upload_version']);
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'ai.run']));
    }

    public function test_scan_retry_exhaustion_marks_run_failed_without_releasing_the_upload(): void
    {
        $run = $this->upload();
        Http::fake(['https://8.8.8.8/scan' => Http::response([], 503)]);
        $job = $this->store->query('jobs', ['type' => 'analyzer.scan'])[0];
        $this->store->put('jobs', $job['id'], array_merge($job, ['attempts' => 4]), $job['version']);
        $result = app(JobRunner::class)->tick(45);
        $this->assertSame(['done' => 0, 'failed' => 1], $result);
        $this->assertSame('failed', $this->store->get('jobs', $job['id'])['status']);
        $this->assertSame('failed', $this->store->get('ai_runs', $run['id'])['status']);
        $this->assertSame('quarantined', $this->store->get('analyzer_uploads', $run['upload_id'])['status']);
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'ai.run']));
    }

    public function test_expiry_during_scan_cannot_enqueue_analysis(): void
    {
        $run = $this->upload();
        Http::fake(['https://8.8.8.8/scan' => function () {
            $this->travel(2)->days();

            return Http::response(['verdict' => 'clean', 'sha256' => hash('sha256', self::SOURCE)]);
        }]);
        $this->assertConflict(fn () => $this->scan($run));
        $this->assertCount(0, $this->store->query('jobs', ['type' => 'ai.run']));
        $this->withSession(['analyzer_grant' => $run['grant_id']])->get('/tools/results/'.$run['id'])->assertGone();
    }
}
