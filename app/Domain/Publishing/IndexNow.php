<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\EndpointPolicy;
use Illuminate\Support\Facades\Http;

final class IndexNow
{
    public function __construct(private RecordStore $store, private Seo $seo, private EndpointPolicy $endpoints) {}

    /** Queued only after a reviewed publication commits; disabled until explicitly configured. */
    public function submit(string $pageId): void
    {
        $site = $this->seo->settings()['site'];
        if (! ($site['indexnow_enabled'] ?? false) || empty($site['indexnow_key'])) {
            return;
        }
        $page = $this->store->get('pages', $pageId)['published_snapshot'] ?? null;
        if (! $page || ! $this->seo->indexable($page)) {
            return;
        }
        if (! preg_match('/^[a-zA-Z0-9-]{8,128}$/D', $site['indexnow_key'])) {
            throw new \RuntimeException('IndexNow verification key is invalid.');
        }
        $origin = rtrim($site['url'], '/');
        if (parse_url($origin, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('IndexNow requires a public HTTPS canonical origin.');
        }
        $response = Http::withOptions($this->endpoints->options('https://api.indexnow.org/indexnow'))
            ->acceptJson()->timeout(10)->connectTimeout(5)->post('https://api.indexnow.org/indexnow', [
                'host' => parse_url($origin, PHP_URL_HOST), 'key' => $site['indexnow_key'],
                'keyLocation' => $origin.'/indexnow-key.txt', 'urlList' => [$this->seo->metadata($page)['canonical']],
            ]);
        $response->throw();
        $this->store->put('system', 'indexnow', ['last_submitted_at' => now()->toISOString(), 'page_id' => $pageId, 'http_status' => $response->status()]);
    }
}
