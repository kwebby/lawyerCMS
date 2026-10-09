<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Business;

use App\Contracts\RecordStore;
use App\Domain\Operations\OperationsService;
use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Http\Request;

final class PipelineController extends Controller
{
    public function __construct(private RecordStore $store, private Access $access, private Audit $audit) {}

    public function index(Request $request): mixed
    {
        $this->access->authorize($request->user(), 'leads.read');

        return response()->json(['data' => $this->store->query('pipelines', [], 100)]);
    }

    public function save(Request $request, ?string $id = null): mixed
    {
        $this->access->authorize($request->user(), 'settings.write');
        $data = $request->validate(['version' => $id ? 'required|integer|min:1' : 'prohibited', 'name' => 'required|string|max:120', 'stages' => 'required|array|min:2|max:20', 'stages.*' => 'array:key,label', 'stages.*.key' => ['required', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,39}$/D'], 'stages.*.label' => 'required|string|max:80', 'active' => 'required|boolean']);
        $saved = $this->store->transaction(function () use ($data, $id, $request) {
            $old = $id ? $this->store->get('pipelines', $id) : null;
            if ($id) {
                abort_unless($old !== null, 404);
                abort_unless(count(array_diff(array_column($old['stages'], 'key'), array_column($data['stages'], 'key'))) === 0, 422, 'Keep existing stage keys to preserve lead history. Rename labels, append stages, or archive this pipeline.');
            }
            $version = $data['version'] ?? null;
            unset($data['version']);
            $record = $id ? $this->store->put('pipelines', $id, $data, $version) : $this->store->create('pipelines', $data);
            $this->audit->log($request->user()->id, 'pipeline.saved', 'pipelines', $record['id']);

            return $record;
        });

        return response()->json(['data' => $saved], $id ? 200 : 201);
    }

    public function move(Request $request, OperationsService $operations, string $id): mixed
    {
        $operations->find($request->user(), 'leads', $id, 'write');
        $data = $request->validate(['version' => 'required|integer|min:1', 'pipeline_id' => 'required|string', 'stage' => 'required|string', 'reason' => 'nullable|string|max:1000']);
        $record = $this->store->transaction(function () use ($data, $id, $request) {
            $lead = $this->store->get('leads', $id);
            $pipeline = $this->store->get('pipelines', $data['pipeline_id']);
            abort_unless($pipeline && $pipeline['active'] && in_array($data['stage'], array_column($pipeline['stages'], 'key'), true), 422, 'Select an active pipeline stage.');
            $saved = $this->store->put('leads', $id, array_replace($lead, ['pipeline_id' => $pipeline['id'], 'pipeline_stage' => $data['stage']]), $data['version']);
            $this->store->create('pipeline_history', ['lead_id' => $id, 'pipeline_id' => $pipeline['id'], 'from' => $lead['pipeline_stage'] ?? null, 'to' => $data['stage'], 'reason' => $data['reason'] ?? null, 'actor_id' => $request->user()->id]);
            $this->audit->log($request->user()->id, 'lead.pipeline_moved', 'leads', $id, ['pipeline_id' => $pipeline['id']]);
            app(Outbox::class)->enqueue('record.changed', ['collection' => 'leads', 'id' => $id, 'actor_id' => $request->user()->id]);

            return $saved;
        });

        return response()->json(['data' => $record]);
    }
}
