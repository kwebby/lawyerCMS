<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Contracts;

interface RecordStore
{
    public function get(string $collection, string $id): ?array;

    public function query(string $collection, array $filters = [], int $limit = 100, string $orderBy = 'created_at', string $direction = 'desc'): array;

    public function create(string $collection, array $data, ?string $id = null): array;

    public function put(string $collection, string $id, array $data, ?int $expectedVersion = null): array;

    public function delete(string $collection, string $id, ?int $expectedVersion = null): void;

    public function transaction(callable $callback): mixed;

    /** Maintenance only: bounded raw snapshot enumeration; keep the app and cron in maintenance while scanning. */
    public function scan(?string $cursor = null, int $limit = 500): array;

    /** Maintenance only: insert raw snapshots preserving IDs, versions and timestamps; reject existing records. */
    public function restore(array $batch): void;
}
