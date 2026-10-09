<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Publishing;

use App\Contracts\RecordStore;
use App\Domain\Publishing\ContentRepository;
use App\Domain\Publishing\Seo;
use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SeoController extends Controller
{
    public function __construct(private RecordStore $store, private Seo $seo, private Access $access, private ContentRepository $content, private Audit $audit) {}

    public function settings(Request $request)
    {
        $this->access->authorize($request->user(), $request->isMethod('patch') ? 'seo.write' : 'seo.read');
        if ($request->isMethod('patch')) {
            $data = $request->validate([
                'site' => ['sometimes', 'array:name,url,locale,email,phone,address,seo,google_verification,bing_verification,indexnow_enabled,indexnow_key'],
                'site.name' => ['sometimes', 'string', 'max:200'], 'site.url' => ['sometimes', 'url:http,https', 'max:500'],
                'site.locale' => ['sometimes', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'], 'site.email' => ['nullable', 'email', 'max:254'],
                'site.phone' => ['nullable', 'string', 'max:80'], 'site.address' => ['nullable', 'string', 'max:1000'],
                'site.indexnow_enabled' => ['sometimes', 'boolean'], 'site.indexnow_key' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9-]{8,128}$/D'],
                'site.google_verification' => ['nullable', 'string', 'max:200', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'site.bing_verification' => ['nullable', 'string', 'max:200', 'regex:/^[a-zA-Z0-9_-]+$/'], 'site.seo' => ['sometimes', 'array'],
                'types' => ['sometimes', 'array:page,article,service,profile,office,tool,about,contact'], 'types.*' => ['array'],
            ]);
            if (isset($data['site']['seo'])) {
                $data['site']['seo'] = $this->seo->validate($data['site']['seo']);
            }
            foreach ($data['types'] ?? [] as $type => $values) {
                $data['types'][$type] = $this->seo->validate($values);
            }
            foreach (array_merge([$data['site']['seo'] ?? []], array_values($data['types'] ?? [])) as $values) {
                if (! empty($values['advanced_schema'])) {
                    abort_unless(array_intersect($request->user()->roles, ['owner', 'admin']) !== [], 403, 'Advanced schema is limited to administrators.');
                }
            }
            if (isset($data['site']['url'])) {
                if (rtrim($data['site']['url'], '/') !== rtrim($this->seo->settings()['site']['url'], '/')) {
                    abort_unless(array_intersect($request->user()->roles, ['owner', 'admin']) !== [], 403, 'Only administrators can change the canonical site origin.');
                }
                $url = parse_url($data['site']['url']);
                abort_if(isset($url['user']) || isset($url['query']) || isset($url['fragment']) || ! empty(trim($url['path'] ?? '', '/')), 422, 'The public site URL must be an origin with no credentials, path, query or fragment.');
                $data['site']['url'] = rtrim($data['site']['url'], '/');
            }
            $this->store->transaction(function () use ($data, $request) {
                $website = $this->store->get('settings', 'website-state');
                if (! empty($website['published'])) {
                    $managed = $this->seo->settings()['site'];
                    foreach (['name', 'email', 'phone', 'address'] as $field) {
                        if (array_key_exists($field, $data['site'] ?? []) && (string) ($data['site'][$field] ?? '') !== (string) ($managed[$field] ?? '')) {
                            throw ValidationException::withMessages(['site.'.$field => 'Update firm and office details in Website settings, then publish the draft.']);
                        }
                        unset($data['site'][$field]);
                    }
                }
                $current = $this->store->get('settings', 'publishing');
                $updated = $current ?? [];
                if (isset($data['site'])) {
                    $updated['site'] = array_replace($updated['site'] ?? [], $data['site']);
                }
                if (isset($data['types'])) {
                    $updated['types'] = array_replace($updated['types'] ?? [], $data['types']);
                }
                $current ? $this->store->put('settings', 'publishing', $updated, $current['version']) : $this->store->create('settings', $updated, 'publishing');
                $this->audit->log($request->user()->id, 'seo.settings_updated', 'settings', 'publishing');
            });
        }

        return response()->json(['data' => $this->seo->settings() + ['website_managed' => ! empty($this->store->get('settings', 'website-state')['published'])]]);
    }

    public function audit(Request $request)
    {
        $this->access->authorize($request->user(), 'seo.read');
        $issues = [];
        $pages = $this->content->published();
        $slugs = array_column($pages, 'slug');
        $titles = [];
        foreach ($pages as $page) {
            $meta = $this->seo->metadata($page);
            $base = ['page_id' => $page['id'], 'title' => $page['title'], 'url' => $this->seo->path($page)];
            if (mb_strlen($meta['title']) < 10 || mb_strlen($meta['title']) > 70) {
                $issues[] = $base + ['category' => 'metadata', 'message' => 'Review the search title length.'];
            }
            if (mb_strlen($meta['description']) < 50) {
                $issues[] = $base + ['category' => 'metadata', 'message' => 'Add a useful search description.'];
            }
            if (isset($titles[$meta['title']])) {
                $issues[] = $base + ['category' => 'duplicate', 'message' => 'Another published page uses this search title.'];
            }
            $titles[$meta['title']] = true;
            if (! $this->seo->indexable($page)) {
                $issues[] = $base + ['category' => 'indexing', 'message' => 'This page is excluded from the sitemap by robots or its canonical URL.'];
            }
            if (! empty($page['review_due_at']) && $page['review_due_at'] < now()->format('Y-m-d')) {
                $issues[] = $base + ['category' => 'review', 'message' => 'The legal review date has passed.'];
            }
            $json = json_encode($this->content->body($page), JSON_UNESCAPED_SLASHES);
            preg_match_all('~"(?:href|url)":"/p/([a-z0-9-]+)"~', $json, $matches);
            foreach (array_unique($matches[1]) as $slug) {
                if (! in_array($slug, $slugs, true)) {
                    $issues[] = $base + ['category' => 'links', 'message' => 'Internal link has no published target: /p/'.$slug];
                }
            }
        }

        return response()->json(['data' => $issues, 'published_count' => count($pages), 'schema_types' => Seo::SCHEMAS]);
    }

    public function visibility(Request $request)
    {
        $this->access->authorize($request->user(), $request->isMethod('post') ? 'seo.write' : 'seo.read');
        if ($request->isMethod('post')) {
            $data = $request->validate(['query' => ['required', 'string', 'max:500'], 'engine' => ['required', 'string', 'max:100'], 'observed_at' => ['required', 'date'], 'mentioned' => ['required', 'boolean'], 'source_url' => ['nullable', 'url:http,https', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000']]);

            return response()->json(['data' => $this->store->create('search_observations', $data + ['actor_id' => $request->user()->id])], 201);
        }

        return response()->json(['data' => $this->store->query('search_observations', [], 1000)]);
    }
}
