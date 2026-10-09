<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use Illuminate\Support\Facades\Http;

final class UploadScanner
{
    public function __construct(private EndpointPolicy $policy) {}

    public function scan(string $bytes, string $name): bool
    {
        $url = config('crm.scanner.url');
        if (! $url) {
            throw new \RuntimeException('No upload scanner configured. Files remain quarantined.');
        }
        $response = Http::withOptions($this->policy->options($url))->withToken(config('crm.scanner.key') ?? '')->timeout(20)->connectTimeout(5)->attach('file', $bytes, $name)->post($url)->throw()->json();
        if (($response['sha256'] ?? '') !== hash('sha256', $bytes)) {
            throw new \RuntimeException('Scanner did not confirm the uploaded file digest.');
        }
        if (! in_array($response['verdict'] ?? '', ['clean', 'infected'], true)) {
            throw new \RuntimeException('Scanner result is unavailable.');
        }

        return $response['verdict'] === 'clean';
    }
}
