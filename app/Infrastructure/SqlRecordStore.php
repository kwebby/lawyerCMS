<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Infrastructure;

use App\Contracts\RecordStore;
use App\Support\Conflict;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class SqlRecordStore implements RecordStore
{
    public function __construct(private ConnectionInterface $db) {}

    private function table(string $collection): Builder
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $collection)) {
            throw new \InvalidArgumentException('Invalid collection.');
        }

        return $this->db->table('crm_records')->where('collection', $collection);
    }

    public function get(string $collection, string $id): ?array
    {
        $query = $this->table($collection)->where('id', $id);
        if ($this->db->transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row ? json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function query(string $collection, array $filters = [], int $limit = 100, string $orderBy = 'created_at', string $direction = 'desc'): array
    {
        $query = $this->table($collection);
        foreach ($filters as $key => $value) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $key)) {
                throw new \InvalidArgumentException('Invalid filter.');
            }
            $query->where($key === 'id' ? 'id' : 'payload->'.$key, $value);
        }
        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $orderBy)) {
            throw new \InvalidArgumentException('Invalid order.');
        }
        $query->orderBy(in_array($orderBy, ['created_at', 'updated_at', 'id']) ? $orderBy : 'payload->'.$orderBy, $direction === 'asc' ? 'asc' : 'desc')->orderBy('id');

        return $query->limit(max(1, min(10000, $limit)))->get()->map(fn ($row) => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR))->all();
    }

    public function create(string $collection, array $data, ?string $id = null): array
    {
        $id ??= (string) Str::uuid();
        $record = array_merge($data, ['id' => $id, 'version' => 1, 'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
        try {
            $this->table($collection)->insert(['collection' => $collection, 'id' => $id, 'version' => 1, 'payload' => json_encode($record, JSON_THROW_ON_ERROR), 'created_at' => $record['created_at'], 'updated_at' => $record['updated_at']]);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505']) || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                throw new Conflict('This record already exists.');
            }
            throw $e;
        }

        return $record;
    }

    public function put(string $collection, string $id, array $data, ?int $expectedVersion = null): array
    {
        $existing = $this->get($collection, $id);
        if (! $existing) {
            if ($expectedVersion !== null) {
                throw new Conflict('This record no longer exists.');
            }

            return $this->create($collection, $data, $id);
        }
        if ($expectedVersion !== null && $existing['version'] !== $expectedVersion) {
            throw new Conflict('A newer revision exists. Reload before saving.');
        }
        $record = array_merge($data, ['id' => $id, 'version' => $existing['version'] + 1, 'created_at' => $existing['created_at'], 'updated_at' => now()->toISOString()]);
        $changed = $this->table($collection)->where('id', $id)->where('version', $existing['version'])->update(['version' => $record['version'], 'payload' => json_encode($record, JSON_THROW_ON_ERROR), 'updated_at' => $record['updated_at']]);
        if (! $changed) {
            throw new Conflict('Concurrent modification. Retry with the current revision.');
        }

        return $record;
    }

    public function delete(string $collection, string $id, ?int $expectedVersion = null): void
    {
        $query = $this->table($collection)->where('id', $id);
        if ($expectedVersion !== null) {
            $query->where('version', $expectedVersion);
        }
        if (! $query->delete() && $expectedVersion !== null) {
            throw new Conflict('Concurrent modification.');
        }
    }

    public function scan(?string $cursor = null, int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        $state = RecordSnapshot::cursor($cursor);
        $query = $this->db->table('crm_records');
        if ($state) {
            if (! isset($state['collection'],$state['id']) || ! is_string($state['collection']) || ! is_string($state['id'])) {
                throw new \InvalidArgumentException('Invalid SQL snapshot cursor.');
            }
            $query->where(function ($q) use ($state) {
                $q->where('collection', '>', $state['collection'])->orWhere(function ($q) use ($state) {
                    $q->where('collection', $state['collection'])->where('id', '>', $state['id']);
                });
            });
        }
        $rows = $query->orderBy('collection')->orderBy('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $records = $rows->map(fn ($row) => ['collection' => $row->collection, 'record' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)])->all();
        $last = $rows->last();

        return ['records' => $records, 'cursor' => $more ? RecordSnapshot::encode(['collection' => $last->collection, 'id' => $last->id]) : null];
    }

    public function restore(array $batch): void
    {
        $batch = RecordSnapshot::batch($batch);
        $this->transaction(function () use ($batch) {
            foreach ($batch as $entry) {
                $collection = $entry['collection'];
                $record = $entry['record'];
                if ($this->get($collection, $record['id'])) {
                    throw new Conflict('Restore destination contains an existing record.');
                }
                $this->table($collection)->insert(['collection' => $collection, 'id' => $record['id'], 'version' => $record['version'], 'payload' => json_encode($record, JSON_THROW_ON_ERROR), 'created_at' => $record['created_at'], 'updated_at' => $record['updated_at']]);
            }
        });
    }

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback, 5);
    }
}
