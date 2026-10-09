<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\Audit;
use App\Support\Conflict;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use Illuminate\Validation\ValidationException;

final class ContentRepository
{
    public function __construct(private RecordStore $store, private PrivateFiles $files, private BlockDocument $blocks, private Audit $audit, private Outbox $outbox) {}

    public function create(string $collection, array $data, array $blocks, string $actor): array
    {
        $path = $this->files->write(json_encode($this->blocks->validate($blocks), JSON_THROW_ON_ERROR), 'content');
        try {
            return $this->store->transaction(function () use ($collection, $data, $path, $actor) {
                if ($collection === 'pages') {
                    $this->assertSlugAvailable($data['slug']);
                }
                $record = $this->store->create($collection, $data + ['kind' => 'written', 'status' => 'draft', 'owner_id' => $actor, 'team_ids' => [], 'client_ids' => [], 'blocks_path' => $path, 'content_updated_at' => now()->toIso8601String()]);
                if ($collection === 'pages') {
                    $this->reserveSlug($record['slug'], $record['id']);
                    $this->reserveTool($record);
                }
                $this->revision($collection, $record, $actor, 'created');
                $this->audit->log($actor, $collection.'.created', $collection, $record['id']);

                return $record;
            });
        } catch (\Throwable $error) {
            $this->files->delete($path);
            throw $error;
        }
    }

    public function update(string $collection, string $id, array $data, ?array $blocks, int $expectedVersion, string $actor): array
    {
        $path = $blocks !== null ? $this->files->write(json_encode($this->blocks->validate($blocks), JSON_THROW_ON_ERROR), 'content') : null;
        try {
            return $this->store->transaction(function () use ($collection, $id, $data, $path, $expectedVersion, $actor) {
                $record = $this->find($collection, $id);
                if ($record['version'] !== $expectedVersion) {
                    throw new Conflict('This document was changed by someone else. Reload before saving.');
                }
                if ($collection === 'pages' && isset($data['slug'])) {
                    $this->assertSlugAvailable($data['slug'], $id);
                }
                $record = array_replace($record, $data, ['status' => 'draft', 'content_updated_at' => now()->toIso8601String(), 'reviewed_by' => null, 'approved_at' => null], $collection === 'pages' ? ['reviewer_name' => null] : []);
                if ($path) {
                    $record['blocks_path'] = $path;
                }
                if ($collection === 'pages') {
                    $this->reserveSlug($record['slug'], $id);
                    $this->reserveTool($record);
                }
                $record = $this->store->put($collection, $id, $record, $expectedVersion);
                $this->revision($collection, $record, $actor, 'edited');
                $this->audit->log($actor, $collection.'.updated', $collection, $id, ['version' => $record['version']]);

                return $record;
            });
        } catch (\Throwable $error) {
            if ($path) {
                $this->files->delete($path);
            } throw $error;
        }
    }

    public function transition(string $collection, string $id, string $action, int $expectedVersion, string $actor): array
    {
        return $this->store->transaction(function () use ($collection, $id, $action, $expectedVersion, $actor) {
            $record = $this->find($collection, $id);
            if ($record['version'] !== $expectedVersion) {
                throw new Conflict('This document has a newer revision.');
            }
            $required = ['review' => ['draft'], 'approve' => ['in_review'], 'publish' => ['approved'], 'unpublish' => ['published', 'draft', 'in_review', 'approved'], 'archive' => ['draft', 'in_review', 'approved', 'published']];
            if (! in_array($record['status'], $required[$action] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This action is not available in the current review stage.']);
            }
            $record['status'] = match ($action) {
                'review' => 'in_review', 'approve' => 'approved', 'publish' => 'published', 'unpublish' => 'draft', 'archive' => 'archived', default => throw new \InvalidArgumentException('Unsupported content action.')
            };
            if ($action === 'approve') {
                $record['reviewed_by'] = $actor;
                $record['approved_at'] = now()->toIso8601String();
                if ($collection === 'pages') {
                    $record['reviewer_name'] = (string) ($this->store->get('users', $actor)['name'] ?? '');
                }
            }
            if ($action === 'publish') {
                if ($collection !== 'pages') {
                    abort(422);
                }
                if (in_array($record['type'], ['article', 'service', 'tool'], true) && (empty($record['author_name']) || empty($record['reviewer_name']) || empty($record['jurisdiction']))) {
                    throw ValidationException::withMessages(['review' => 'Legal pages need an author, reviewer and jurisdiction before publishing.']);
                }
                if ($record['type'] === 'article' && empty($record['sources'])) {
                    throw ValidationException::withMessages(['sources' => 'Legal articles need at least one reviewed source.']);
                }
                app(Seo::class)->assertVisibleSchema($record, $this->body($record));
                $this->assertSlugAvailable($record['slug'], $id);
                $oldSlug = $record['published_snapshot']['slug'] ?? null;
                $record['published_at'] = $record['published_at'] ?? now()->toIso8601String();
                $snapshot = $record;
                unset($snapshot['published_snapshot']);
                $snapshot['version'] = $expectedVersion + 1;
                $record['published_snapshot'] = $snapshot;
                $routeId = hash('sha256', $record['slug']);
                $route = $this->store->get('page_routes', $routeId);
                $routeData = ['page_id' => $id, 'slug' => $record['slug'], 'status' => 'published'];
                $route ? $this->store->put('page_routes', $routeId, $routeData) : $this->store->create('page_routes', $routeData, $routeId);
                if ($oldSlug && $oldSlug !== $record['slug']) {
                    $oldId = hash('sha256', $oldSlug);
                    $old = $this->store->get('page_routes', $oldId);
                    if ($old) {
                        $this->store->put('page_routes', $oldId, ['page_id' => $id, 'slug' => $oldSlug, 'status' => 'redirect', 'target' => $record['slug']]);
                    }
                }
                $this->outbox->enqueue('publishing.published', ['page_id' => $id, 'version' => $expectedVersion + 1], 'publish-'.$id.'-'.($expectedVersion + 1));
            }
            if (in_array($action, ['unpublish', 'archive'], true)) {
                unset($record['published_snapshot']);
                if ($collection === 'pages') {
                    foreach ($this->store->query('page_routes', ['page_id' => $id], 1000) as $route) {
                        $this->store->put('page_routes', $route['id'], array_replace($route, ['status' => $action === 'archive' ? 'gone' : 'unpublished']));
                    }
                }
            }
            $record = $this->store->put($collection, $id, $record, $expectedVersion);
            $this->revision($collection, $record, $actor, $action);
            $this->audit->log($actor, $collection.'.'.$action, $collection, $id, ['version' => $record['version']]);

            return $record;
        });
    }

    public function find(string $collection, string $id): array
    {
        $record = $this->store->get($collection, $id);
        abort_unless($record !== null, 404);
        abort_unless(($record['kind'] ?? '') === 'written' || $collection === 'pages', 422, 'This is an uploaded file, not a written document.');

        return $record;
    }

    /** The author and everyone who edited the record since its last approval. */
    public function contributors(string $collection, array $record): array
    {
        $ids = [$record['owner_id'] ?? null];
        foreach ($this->store->query('content_revisions', ['collection' => $collection, 'resource_id' => $record['id']], 1000, 'number', 'desc') as $revision) {
            if ($revision['action'] === 'approve') {
                break;
            }
            if (in_array($revision['action'], ['created', 'edited'], true)) {
                $ids[] = $revision['actor_id'];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function body(array $record): array
    {
        return isset($record['blocks_path']) ? json_decode($this->files->read($record['blocks_path']), true, 512, JSON_THROW_ON_ERROR) : ($record['blocks'] ?? []);
    }

    public function response(array $record, bool $body = true): array
    {
        $result = $record;
        if ($body) {
            $result['blocks'] = $this->body($record);
        }
        $result['published_version'] = $record['published_snapshot']['version'] ?? null;
        unset($result['blocks_path'], $result['published_snapshot'], $result['path']);

        return $result;
    }

    public function published(): array
    {
        return array_values(array_filter(array_map(fn ($record) => $record['published_snapshot'] ?? null, $this->store->query('pages', [], 10000)), fn ($item) => $item !== null));
    }

    private function revision(string $collection, array $record, string $actor, string $action): void
    {
        $snapshot = $record;
        unset($snapshot['published_snapshot']);
        $this->store->create('content_revisions', ['collection' => $collection, 'resource_id' => $record['id'], 'number' => $record['version'], 'actor_id' => $actor, 'action' => $action, 'snapshot' => $snapshot]);
    }

    private function reserveTool(array $record): void
    {
        if (empty($record['tool_slug'])) {
            return;
        }
        if (($record['type'] ?? '') !== 'tool' || ! in_array($record['tool_slug'], Seo::TOOLS, true)) {
            throw ValidationException::withMessages(['tool_slug' => 'Choose a supported tool mapping on a tool page.']);
        }
        $existing = $this->store->get('page_tools', $record['tool_slug']);
        if ($existing && $existing['page_id'] !== $record['id']) {
            throw ValidationException::withMessages(['tool_slug' => 'This tool already has a content page.']);
        }
        if (! $existing) {
            $this->store->create('page_tools', ['page_id' => $record['id']], $record['tool_slug']);
        }
    }

    private function reserveSlug(string $slug, string $pageId): void
    {
        $routeId = hash('sha256', $slug);
        $route = $this->store->get('page_routes', $routeId);
        if (! $route) {
            $this->store->create('page_routes', ['page_id' => $pageId, 'slug' => $slug, 'status' => 'draft'], $routeId);
        }
    }

    private function assertSlugAvailable(string $slug, ?string $exceptId = null): void
    {
        $route = $this->store->get('page_routes', hash('sha256', $slug));
        if ($route && $route['page_id'] !== $exceptId) {
            throw ValidationException::withMessages(['slug' => 'This URL is already reserved.']);
        }
        foreach ($this->store->query('pages', ['slug' => $slug], 10) as $page) {
            if ($page['id'] !== $exceptId) {
                throw ValidationException::withMessages(['slug' => 'This slug is already in use.']);
            }
        }
    }
}
