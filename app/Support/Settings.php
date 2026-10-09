<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;
use Illuminate\Support\Facades\Crypt;

final class Settings
{
    public function __construct(private RecordStore $store) {}

    public function get(string $section, bool $secrets = false): array
    {
        $record = $this->store->get('settings', $section) ?? [];
        foreach (['password', 'api_key', 'secret'] as $key) {
            if (isset($record[$key])) {
                $record[$key] = $secrets ? Crypt::decryptString($record[$key]) : null;
                $record[$key.'_configured'] = true;
            }
        }

        return $record;
    }

    public function save(string $section, array $data): array
    {
        return $this->store->transaction(function () use ($section, $data) {
            $old = $this->store->get('settings', $section) ?? [];
            foreach (['password', 'api_key', 'secret'] as $key) {
                if (isset($data[$key]) && $data[$key] !== '') {
                    $data[$key] = Crypt::encryptString($data[$key]);
                } else {
                    unset($data[$key]);
                }
            }
            $this->store->put('settings', $section, array_merge($old, $data), $old['version'] ?? null);

            return $this->get($section);
        });
    }
}
