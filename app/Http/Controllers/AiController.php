<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\AiGateway;
use App\Support\Audit;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final class AiController extends Controller
{
    public function __construct(private RecordStore $store, private Access $access, private Outbox $outbox, private AiGateway $ai, private PrivateFiles $files) {}

    private function response(Request $request, array $run): array
    {
        $this->access->authorize($request->user(), 'ai_runs.read', $run);
        foreach ($run['source_versions'] ?? [] as $source) {
            $document = $this->store->get('documents', $source['id']);
            abort_unless($document !== null, 403);
            $this->access->authorize($request->user(), 'documents.read', $document);
        }
        if (isset($run['result_path'])) {
            $run['result'] = $this->files->read($run['result_path']);
            unset($run['result_path']);
        }

        return $this->access->present($request->user(), 'ai_runs', $run);
    }

    public function index(Request $request): mixed
    {
        $records = $this->access->filter($request->user(), 'ai_runs.read', $this->store->each('ai_runs', ['context' => 'staff']));
        $safe = [];
        foreach ($records as $run) {
            try {
                $safe[] = $this->response($request, $run);
            } catch (AuthorizationException) {
            }
        }

        return response()->json(['data' => $safe, 'configured' => $this->ai->configured()]);
    }

    public function create(Request $request, Settings $settings): mixed
    {
        $this->access->authorize($request->user(), 'ai_runs.write');
        abort_unless($this->ai->configured(), 422, 'Configure an AI provider in Practice settings before creating a run.');
        $data = $request->validate(['kind' => 'required|in:summary,chronology,draft,document_request,engagement_letter,client_update,notice_reply', 'document_ids' => 'required|array|min:1|max:10', 'document_ids.*' => 'string', 'instructions' => 'nullable|string|max:4000']);
        $versions = [];
        foreach ($data['document_ids'] as $id) {
            $document = $this->store->get('documents', $id);
            abort_unless($document !== null, 422, 'Document not found.');
            $this->access->authorize($request->user(), 'documents.read', $document);
            abort_unless(($document['kind'] ?? '') === 'written' || ($document['status'] ?? '') === 'clean', 422, 'All uploaded sources must be scanned.');
            $versions[] = ['id' => $id, 'version' => $document['version'], 'title' => $document['title'] ?? $document['name']];
        }
        $record = $this->store->transaction(function () use ($request, $data, $versions, $settings) {
            $key = 'ai-'.now()->format('Y-m-d');
            $budget = $this->store->get('budgets', $key);
            $limit = min(config('crm.ai_daily_limit'), $settings->get('ai')['daily_limit'] ?? 50);
            abort_if(($budget['used'] ?? 0) >= $limit, 429, 'The daily AI run budget is exhausted.');
            $this->store->put('budgets', $key, ['used' => ($budget['used'] ?? 0) + 1, 'limit' => $limit], $budget['version'] ?? null);
            $record = $this->store->create('ai_runs', ['context' => 'staff', 'kind' => $data['kind'], 'instructions' => $data['instructions'] ?? '', 'source_versions' => $versions, 'prompt_version' => 'legal-assist-v1', 'owner_id' => $request->user()->id, 'team_ids' => [], 'client_ids' => [], 'status' => 'queued']);
            $this->outbox->enqueue('ai.run', ['run_id' => $record['id']], $record['id']);

            return $record;
        });

        return response()->json(['data' => $record], 202);
    }

    public function show(Request $request, string $id): mixed
    {
        $record = $this->store->get('ai_runs', $id);
        abort_unless($record && $record['context'] === 'staff', 404);

        return response()->json(['data' => $this->response($request, $record)]);
    }

    public function approve(Request $request, string $id, Audit $audit): mixed
    {
        $data = $request->validate(['expected_version' => 'required|integer', 'decision' => 'required|in:approved,rejected', 'review_notes' => 'required|string|max:3000']);
        $record = $this->store->transaction(function () use ($request, $id, $data, $audit) {
            $run = $this->store->get('ai_runs', $id);
            abort_unless($run && $run['context'] === 'staff', 404);
            $this->response($request, $run);
            $this->access->authorize($request->user(), 'documents.approve', ['owner_id' => $run['owner_id']]);
            abort_unless($run['status'] === 'review', 422, 'This run is not awaiting review.');
            foreach ($run['source_versions'] as $source) {
                abort_unless($this->store->get('documents', $source['id'])['version'] === $source['version'], 409, 'Source changed after drafting. Create a new run.');
            }
            $record = $this->store->put('ai_runs', $id, array_merge($run, ['status' => $data['decision'], 'review_notes' => $data['review_notes'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()->toISOString()]), $data['expected_version']);
            $audit->log($request->user()->id, 'ai.reviewed', 'ai_runs', $id, ['decision' => $data['decision']]);

            return $record;
        });

        return response()->json(['data' => $this->response($request, $record)]);
    }
}
