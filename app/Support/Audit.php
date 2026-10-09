<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;

final class Audit
{
    public function __construct(private RecordStore $store) {}

    public function log(?string $actorId, string $action, string $collection, ?string $recordId, array $meta = []): void
    {
        $this->store->create('audit', ['actor_id' => $actorId, 'action' => $action, 'collection' => $collection, 'record_id' => $recordId, 'meta' => array_diff_key($meta, array_flip(['password', 'secret', 'token', 'body', 'content'])), 'occurred_at' => now()->toISOString()]);
    }
}
