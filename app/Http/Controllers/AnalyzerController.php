<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Contracts\RecordStore;
use App\Domain\Publishing\BlockDocument;
use App\Domain\Publishing\ContentRepository;
use App\Domain\Publishing\Seo;
use App\Support\AiGateway;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class AnalyzerController extends Controller
{
    private const TOOLS = ['notice-explainer' => 'Notice explainer', 'consultation-preparation' => 'Consultation preparation', 'document-completeness' => 'Document completeness checker'];

    public function __construct(private RecordStore $store, private Settings $settings, private AiGateway $ai, private Outbox $outbox, private PrivateFiles $files) {}

    private function enabled(): bool
    {
        return $this->ai->configured() && ($this->settings->get('ai')['public_tools_approved'] ?? false) && config('crm.scanner.url') && config('services.turnstile.secret');
    }

    public function page(Request $request, string $tool): mixed
    {
        abort_unless(isset(self::TOOLS[$tool]), 404);
        $seo = app(Seo::class);
        $site = $seo->settings()['site'];
        if (app()->environment('production') && rtrim($request->getSchemeAndHttpHost(), '/') !== rtrim($site['url'], '/')) {
            return redirect()->away(rtrim($site['url'], '/').'/tools/'.$tool, 301);
        }
        $published = $seo->publishedTool($tool);
        $page = $published ?? ['id' => 'tool-'.$tool, 'title' => self::TOOLS[$tool], 'type' => 'tool', 'tool_slug' => $tool, 'slug' => $tool, 'locale' => 'en', 'summary' => 'Understand your documents and prepare for a professional legal consultation.', 'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString()];
        $meta = $seo->metadata($page);
        $enabled = $this->enabled();
        if (! $published || ! $enabled) {
            $meta['robots'] = 'noindex,nofollow';
        }
        $nonce = base64_encode(random_bytes(18));
        $bodyHtml = $published ? app(BlockDocument::class)->html(app(ContentRepository::class)->body($published)) : '';

        return response()->view('analyzers/tool', ['tool' => $tool, 'title' => $page['title'], 'page' => $page, 'site' => $site, 'meta' => $meta, 'nonce' => $nonce, 'bodyHtml' => $bodyHtml, 'processingPolicy' => $this->ai->policyFingerprint(), 'enabled' => $enabled, 'siteKey' => config('services.turnstile.site_key'), 'stage' => 'email', 'provider' => ucfirst($this->settings->get('ai')['provider'] ?? 'configured provider')])->header('X-Robots-Tag', $meta['robots'])->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; frame-src https://challenges.cloudflare.com; connect-src 'self'; img-src 'self'; form-action 'self'; base-uri 'self'");
    }

    public function request(Request $request, string $tool): mixed
    {
        abort_unless(isset(self::TOOLS[$tool]) && $this->enabled(), 503, 'Public analysis is awaiting administrator configuration and jurisdiction review.');
        $data = $request->validate(['email' => 'required|email|max:254', 'name' => 'required|string|max:120', 'jurisdiction' => 'required|string|max:120', 'processing_consent' => 'accepted', 'processing_policy' => 'required|string|size:64|regex:/^[a-f0-9]+$/D', 'marketing_consent' => 'boolean', 'website' => 'nullable|size:0', 'cf-turnstile-response' => 'required|string|max:2048']);
        $this->ai->assertPublicPolicy($data);
        $bot = Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => config('services.turnstile.secret'), 'response' => $data['cf-turnstile-response'], 'remoteip' => $request->ip()])->throw()->json();
        abort_unless(($bot['success'] ?? false) && ($bot['hostname'] ?? '') === parse_url(config('app.url'), PHP_URL_HOST), 422, 'Verification could not be completed.');
        $token = Str::random(64);
        $this->store->transaction(function () use ($data, $tool, $token, $request) {
            $this->ai->assertPublicPolicy($data);
            foreach ([hash('sha256', strtolower($data['email'])), hash('sha256', $request->ip())] as $identity) {
                $key = 'public-'.now()->format('Y-m-d').'-'.$identity;
                $quota = $this->store->get('budgets', $key);
                abort_if(($quota['used'] ?? 0) >= 3, 429, 'The daily analysis limit has been reached.');
                $this->store->put('budgets', $key, ['used' => ($quota['used'] ?? 0) + 1], $quota['version'] ?? null);
            }
            $this->store->create('analyzer_grants', ['tool' => $tool, 'email' => strtolower($data['email']), 'name' => $data['name'], 'jurisdiction' => $data['jurisdiction'], 'marketing_consent' => $data['marketing_consent'] ?? false, 'consent_version' => 'public-analysis-v2', 'processing_policy' => $data['processing_policy'], 'expires_at' => now()->addDay()->toISOString(), 'verified_at' => null, 'used_at' => null], hash('sha256', $token));
            $this->outbox->enqueue('email', ['to' => $data['email'], 'subject' => 'Verify your private document analysis request', 'body' => 'Verify your email to continue: '.url('/tools/verify/'.$token)]);
        });

        return response()->view('analyzers/tool', ['tool' => $tool, 'title' => self::TOOLS[$tool], 'stage' => 'sent', 'enabled' => true]);
    }

    public function verify(Request $request, string $token): mixed
    {
        $id = hash('sha256', $token);
        $grant = $this->store->get('analyzer_grants', $id);
        abort_unless($grant && $grant['expires_at'] > now()->toISOString(), 410, 'This verification link has expired.');
        $this->store->transaction(function () use ($id) {
            $grant = $this->store->get('analyzer_grants', $id);
            if (! $grant['verified_at']) {
                $grant['verified_at'] = now()->toISOString();
                $this->store->put('analyzer_grants', $id, $grant, $grant['version']);
            }
        });
        $request->session()->regenerate();
        $request->session()->put('analyzer_grant', $id);

        return response()->view('analyzers/tool', ['tool' => $grant['tool'], 'title' => self::TOOLS[$grant['tool']], 'stage' => 'upload', 'enabled' => $this->enabled()]);
    }

    public function upload(Request $request): mixed
    {
        abort_unless($this->enabled(), 503);
        $grantId = $request->session()->get('analyzer_grant');
        $grant = $grantId ? $this->store->get('analyzer_grants', $grantId) : null;
        abort_unless($grant && $grant['verified_at'] && $grant['expires_at'] > now()->toISOString(), 403, 'Verify your email before uploading.');
        $this->ai->assertPublicPolicy($grant);
        $request->validate(['file' => 'required|file|max:10240|mimes:pdf,txt,docx']);
        $file = $request->file('file');
        $bytes = $file->getContent();
        $path = $this->files->write($bytes, 'temporary');
        try {
            $run = $this->store->transaction(function () use ($grantId, $file, $path, $bytes) {
                $grant = $this->store->get('analyzer_grants', $grantId);
                abort_unless($grant && $grant['verified_at'] && $grant['expires_at'] > now()->toISOString(), 410, 'This analysis request has expired.');
                $this->ai->assertPublicPolicy($grant);
                abort_if($grant['used_at'], 409, 'This link has already been used.');
                $budgetKey = 'ai-'.now()->format('Y-m-d');
                $budget = $this->store->get('budgets', $budgetKey);
                $limit = min((int) config('crm.ai_daily_limit'), (int) ($this->settings->get('ai')['daily_limit'] ?? 50));
                abort_if(($budget['used'] ?? 0) >= $limit, 429, 'The daily analysis capacity has been reached.');
                $this->store->put('budgets', $budgetKey, ['used' => ($budget['used'] ?? 0) + 1, 'limit' => $limit], $budget['version'] ?? null);
                $upload = $this->store->create('analyzer_uploads', ['path' => $path, 'name' => basename($file->getClientOriginalName()), 'mime' => $file->getMimeType(), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'quarantined', 'expires_at' => $grant['expires_at']]);
                $run = $this->store->create('ai_runs', ['context' => 'public', 'kind' => $grant['tool'], 'grant_id' => $grantId, 'upload_id' => $upload['id'], 'upload_version' => $upload['version'], 'upload_sha256' => $upload['sha256'], 'processing_policy' => $grant['processing_policy'], 'status' => 'queued', 'stage' => 'scan', 'instructions' => 'Jurisdiction provided by visitor: '.$grant['jurisdiction'].'. Explain supplied material and prepare questions. Do not provide definitive legal advice.', 'expires_at' => $grant['expires_at'], 'prompt_version' => 'public-analysis-v2']);
                $this->store->put('analyzer_grants', $grantId, array_merge($grant, ['used_at' => now()->toISOString(), 'run_id' => $run['id']]), $grant['version']);
                $this->outbox->enqueue('analyzer.scan', ['run_id' => $run['id']], $run['id']);

                return $run;
            });
        } catch (\Throwable $e) {
            $this->files->delete($path);
            throw $e;
        }

        return redirect('/tools/results/'.$run['id']);
    }

    public function result(Request $request, string $id): mixed
    {
        $run = $this->store->get('ai_runs', $id);
        abort_unless($run && $run['context'] === 'public' && $run['expires_at'] > now()->toISOString(), 410, 'This result has expired.');
        abort_unless($request->session()->get('analyzer_grant') === $run['grant_id'], 403, 'Open the verification link in your email to access this result.');
        $result = isset($run['result_path']) ? $this->files->read($run['result_path']) : null;

        return response()->view('analyzers/tool', ['tool' => $run['kind'], 'title' => self::TOOLS[$run['kind']], 'stage' => 'result', 'run' => $run, 'result' => $result, 'enabled' => true])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function consult(Request $request, string $id): mixed
    {
        $request->validate(['transfer_consent' => 'accepted']);
        $run = $this->store->get('ai_runs', $id);
        abort_unless($run && $run['context'] === 'public' && $run['expires_at'] > now()->toISOString() && $request->session()->get('analyzer_grant') === $run['grant_id'], 403);
        $grant = $this->store->get('analyzer_grants', $run['grant_id']);
        $owner = null;
        foreach ($this->store->each('users') as $user) {
            if (($user['status'] ?? 'active') === 'active' && array_intersect($user['roles'] ?? [], ['intake', 'owner'])) {
                $owner = $user['id'];
                break;
            }
        }
        $lead = $this->store->transaction(function () use ($grant, $run, $owner) {
            $key = hash('sha256', 'analyzer-lead:'.$run['id']);
            if ($existing = $this->store->get('leads', $key)) {
                return $existing;
            }
            $lead = $this->store->create('leads', ['name' => $grant['name'], 'email' => $grant['email'], 'jurisdiction' => $grant['jurisdiction'], 'source' => 'tool:'.$run['kind'], 'status' => 'new', 'stage' => 'new', 'owner_id' => $owner, 'marketing_consent' => $grant['marketing_consent'], 'issue_category' => $run['kind'], 'email_verified_at' => $grant['verified_at'], 'next_action' => 'Contact to arrange consultation. Private analysis is temporary; request explicit document transfer during intake.'], $key);
            $this->outbox->enqueue('record.changed', ['collection' => 'leads', 'record_id' => $lead['id'], 'user_ids' => array_filter([$lead['owner_id']])], $key);

            return $lead;
        });

        return back()->with('status', 'Your consultation request has been sent. The practice will contact you. This does not establish representation.');
    }
}
