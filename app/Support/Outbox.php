<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;
use Illuminate\Support\Str;

final class Outbox
{
    public function __construct(private RecordStore $store) {}

    public function enqueue(string $type, array $payload, ?string $dedupeKey = null): array
    {
        return $this->store->transaction(function () use ($type, $payload, $dedupeKey) {
            $id = $dedupeKey ? hash('sha256', $type.':'.$dedupeKey) : (string) Str::uuid();
            $existing = $this->store->get('jobs', $id);

            return $existing ?? $this->store->create('jobs', ['type' => $type, 'payload' => $payload, 'status' => 'pending', 'attempts' => 0, 'available_at' => now()->toISOString(), 'lease_until' => null, 'lease_token' => null], $id);
        });
    }
}
