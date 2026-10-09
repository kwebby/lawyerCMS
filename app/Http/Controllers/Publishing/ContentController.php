<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Publishing;

use App\Contracts\RecordStore;
use App\Domain\Publishing\ContentRepository;
use App\Domain\Publishing\DocumentExport;
use App\Domain\Publishing\Seo;
use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ContentController extends Controller
{
    public function __construct(private RecordStore $store, private ContentRepository $content, private Access $access, private Seo $seo, private Audit $audit) {}

    private function collection(Request $request): string
    {
        return str_starts_with($request->path(), 'api/v1/pages') ? 'pages' : 'documents';
    }

    public function index(Request $request)
    {
        $collection = $this->collection($request);
        $records = array_values(array_filter($this->access->filter($request->user(), $collection.'.read', $this->store->each($collection)), fn ($record) => ($record['status'] ?? '') !== 'archived'));

        return response()->json(['data' => array_map(fn ($record) => $this->content->response($record, false), $records)]);
    }

    public function show(Request $request, string $id)
    {
        $collection = $this->collection($request);
        $record = $this->content->find($collection, $id);
        $this->access->authorize($request->user(), $collection.'.read', $record);

        return response()->json(['data' => $this->content->response($record)]);
    }

    public function store(Request $request)
    {
        $collection = $this->collection($request);
        $this->access->authorize($request->user(), $collection.'.write');
        [$data, $blocks] = $this->validateData($request, false);
        if ($collection === 'documents' && ! empty($data['matter_id'])) {
            $matter = $this->store->get('matters', $data['matter_id']);
            abort_unless($matter !== null, 422, 'Matter not found.');
            $this->access->authorize($request->user(), 'matters.read', $matter);
            $data['team_ids'] = array_values(array_unique(array_merge($matter['team_ids'] ?? [], [$matter['owner_id']])));
            $data['confidentiality'] = $matter['confidentiality'] ?? 'standard';
        }
        $record = $this->content->create($collection, $data, $blocks ?? [], $request->user()->id);

        return response()->json(['data' => $this->content->response($record)], 201);
    }

    public function update(Request $request, string $id)
    {
        $collection = $this->collection($request);
        $record = $this->content->find($collection, $id);
        $this->access->authorize($request->user(), $collection.'.write', $record);
        [$data, $blocks] = $this->validateData($request, true);
        unset($data['matter_id']); // Moving between legal matters requires a separate grant-aware operation.
        $record = $this->content->update($collection, $id, $data, $blocks, (int) $request->input('expected_version'), $request->user()->id);

        return response()->json(['data' => $this->content->response($record)]);
    }

    public function transition(Request $request, string $id, string $action)
    {
        $collection = $this->collection($request);
        $record = $this->content->find($collection, $id);
        $ability = match ($action) {
            'review' => 'write', 'approve' => 'review', 'publish', 'unpublish', 'archive' => $collection === 'pages' ? 'publish' : 'write', default => 'write'
        };
        $this->access->authorize($request->user(), $collection.'.'.$ability, $record);
        $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $record = $this->content->transition($collection, $id, $action, (int) $request->input('expected_version'), $request->user()->id);

        return response()->json(['data' => $this->content->response($record)]);
    }

    public function destroy(Request $request, string $id)
    {
        return $this->transition($request, $id, 'archive');
    }

    public function revisions(Request $request, string $id)
    {
        $collection = $this->collection($request);
        $record = $this->content->find($collection, $id);
        $this->access->authorize($request->user(), $collection.'.write', $record);
        $revisions = $this->store->query('content_revisions', ['collection' => $collection, 'resource_id' => $id], 1000, 'number', 'desc');

        return response()->json(['data' => array_map(function ($revision) {
            $revision['snapshot'] = $this->content->response($revision['snapshot'], false);

            return $revision;
        }, $revisions)]);
    }

    public function revision(Request $request, string $id, string $revisionId)
    {
        $collection = $this->collection($request);
        $record = $this->content->find($collection, $id);
        $this->access->authorize($request->user(), $collection.'.write', $record);
        $revision = $this->store->get('content_revisions', $revisionId);
        abort_unless($revision && $revision['resource_id'] === $id && $revision['collection'] === $collection, 404);

        return response()->json(['data' => $this->content->response($revision['snapshot'])]);
    }

    public function comments(Request $request, string $id)
    {
        $collection = $this->collection($request);
        $record = $this->content->find($collection, $id);
        $this->access->authorize($request->user(), $collection.'.write', $record);
        if ($request->isMethod('post')) {
            $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'block_id' => ['nullable', 'string', 'max:100'], 'document_version' => ['required', 'integer', 'min:1']]);
            $comment = $this->store->transaction(function () use ($data, $request, $collection, $id) {
                $comment = $this->store->create('content_comments', $data + ['resource_id' => $id, 'collection' => $collection, 'actor_id' => $request->user()->id, 'actor_name' => $request->user()->name]);
                $this->audit->log($request->user()->id, $collection.'.commented', $collection, $id);

                return $comment;
            });

            return response()->json(['data' => $comment], 201);
        }

        return response()->json(['data' => $this->store->query('content_comments', ['collection' => $collection, 'resource_id' => $id], 1000, 'created_at', 'asc')]);
    }

    public function export(Request $request, string $id, string $format, DocumentExport $export)
    {
        $collection = $this->collection($request);
        $record = $this->content->find($collection, $id);
        $this->access->authorize($request->user(), $collection.'.read', $record);
        $bytes = $export->generate($record, $this->content->body($record), $format, $request->user());
        $this->audit->log($request->user()->id, $collection.'.exported', $collection, $id, ['format' => $format, 'version' => $record['version']]);

        return response($bytes)->withHeaders(['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Content-Disposition' => 'attachment; filename="document-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $id).'.'.$format.'"', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function validatePublicBlocks(array $blocks): void
    {
        foreach ($blocks as $block) {
            abort_if(! empty($block['props']['fileId']), 422, 'Private attachments cannot be published. Use an approved public theme asset.');
            if (($block['type'] ?? '') === 'image' && ! empty($block['props']['url'])) {
                abort_unless(preg_match('~^/theme-assets/[a-zA-Z0-9_-]+/(?:assets/)?[a-zA-Z0-9_./-]+$~', $block['props']['url']), 422, 'Use a public theme image asset.');
            }
            $this->validatePublicBlocks($block['children'] ?? []);
        }
    }

    private function validateData(Request $request, bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $rules = ['title' => [$required, 'string', 'max:200'], 'blocks' => ['sometimes', 'array', 'max:1000'], 'expected_version' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1']];
        if ($this->collection($request) === 'pages') {
            $rules += [
                'slug' => [$required, 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'type' => [$required, Rule::in(['page', 'article', 'service', 'profile', 'office', 'tool', 'about', 'contact'])],
                'tool_slug' => ['nullable', Rule::in(Seo::TOOLS)],
                'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
                'translation_group' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'summary' => ['sometimes', 'nullable', 'string', 'max:1000'], 'seo' => ['sometimes', 'array'],
                'author_name' => ['nullable', 'string', 'max:150'], 'reviewer_name' => ['nullable', 'string', 'max:150'],
                'jurisdiction' => ['nullable', 'string', 'max:150'], 'review_due_at' => ['nullable', 'date_format:Y-m-d'],
                'sources' => ['sometimes', 'array', 'max:50'], 'sources.*.title' => ['required', 'string', 'max:200'], 'sources.*.url' => ['required', 'url:http,https', 'max:2000'],
            ];
        } else {
            $rules += ['matter_id' => ['nullable', 'string', 'max:100'], 'summary' => ['nullable', 'string', 'max:1000']];
        }
        $data = $request->validate($rules);
        $blocks = $data['blocks'] ?? null;
        unset($data['blocks'], $data['expected_version']);
        if (isset($data['seo'])) {
            $data['seo'] = $this->seo->validate($data['seo']);
            if (! empty($data['seo']['advanced_schema'])) {
                abort_unless(array_intersect($request->user()->roles, ['owner', 'admin']) !== [], 403, 'Advanced schema is limited to administrators.');
            }
        }
        if ($blocks !== null && $this->collection($request) === 'pages') {
            $this->validatePublicBlocks($blocks);
        }
        if (! $updating && $this->collection($request) === 'pages') {
            $data += ['locale' => 'en', 'seo' => [], 'sources' => [], 'summary' => ''];
        }

        return [$data, $blocks];
    }
}
