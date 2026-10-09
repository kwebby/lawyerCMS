<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Publishing;

use App\Contracts\RecordStore;
use App\Domain\Publishing\BlockDocument;
use App\Domain\Publishing\ContentRepository;
use App\Domain\Publishing\Seo;
use App\Domain\Publishing\Themes;
use App\Domain\Publishing\Website;
use App\Domain\Publishing\WebsiteFonts;
use App\Domain\Publishing\WebsiteMedia;
use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\Request;

final class PublicController extends Controller
{
    public function __construct(private RecordStore $store, private ContentRepository $content, private Seo $seo, private Themes $themes, private BlockDocument $blocks, private Access $access, private Website $website) {}

    public function home(Request $request)
    {
        return $this->page($request, 'home');
    }

    public function page(Request $request, string $slug)
    {
        $site = $this->seo->settings()['site'];
        if (app()->environment('production') && rtrim($request->getSchemeAndHttpHost(), '/') !== rtrim($site['url'], '/')) {
            return redirect()->away(rtrim($site['url'], '/').($slug === 'home' ? '/' : '/p/'.$slug), 301);
        }
        if ($slug === 'home' && ($website = $this->website->published())) {
            if ($request->path() !== '/') {
                return redirect('/', 301);
            }

            return $this->render($this->websiteHome($website), $this->themes->active(), false, $website);
        }
        $route = $this->store->get('page_routes', hash('sha256', $slug));
        if (! $route && $slug === 'home') {
            return response()->view('public.setup', ['site' => $site])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
        }
        abort_unless($route !== null, 404);
        if ($route['status'] === 'gone') {
            abort(410);
        }
        abort_if($route['status'] === 'unpublished' || $route['status'] === 'draft', 404);
        $record = $this->store->get('pages', $route['page_id']);
        $page = $record['published_snapshot'] ?? null;
        abort_unless($page !== null, 404);
        abort_unless($this->seo->officeAvailable($page), 404);
        if ($route['status'] === 'redirect' || ! empty($page['tool_slug']) || ($slug === 'home' && $request->path() !== '/')) {
            return redirect($this->seo->path($page), 301);
        }

        return $this->render($page, $this->themes->active(), false);
    }

    public function preview(Request $request, string $id)
    {
        $page = $this->content->find('pages', $id);
        $this->access->authorize($request->user(), 'pages.read', $page);

        return $this->render($page, $this->themes->active(), true);
    }

    public function themePreview(Request $request, string $id)
    {
        $this->access->authorize($request->user(), 'themes.read');
        $page = $this->content->published()[0] ?? ['id' => 'preview', 'title' => 'A considered approach to legal work', 'slug' => 'preview', 'type' => 'page', 'locale' => 'en', 'summary' => 'Preview how your firm’s content appears with this theme.', 'blocks' => [['type' => 'heading', 'props' => ['level' => 2], 'content' => 'Practical guidance, clearly explained'], ['type' => 'paragraph', 'content' => 'Your published writing appears here. Colors, typography and page sections come from the theme; your firm’s content stays in the publishing workspace.']], 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()];

        return $this->render($page, $this->themes->get($id), true, null, false);
    }

    public function websitePreview(Request $request)
    {
        $this->access->authorize($request->user(), 'pages.read');
        $website = $this->website->previewDocument();

        return $this->render($this->websiteHome($website), $this->themes->active(), true, $website);
    }

    private function websiteHome(array $website): array
    {
        $route = $this->store->get('page_routes', hash('sha256', 'home'));
        $published = ($route['status'] ?? '') === 'published' ? ($this->store->get('pages', $route['page_id'])['published_snapshot'] ?? []) : [];
        $published = ($published['slug'] ?? null) === 'home' ? $published : [];
        $timestamp = $this->store->get('settings', 'website-state')['published']['published_at'] ?? now()->toIso8601String();

        return array_replace($published, ['id' => 'website-home', 'slug' => 'home', 'type' => 'page', 'title' => $website['home']['title'], 'summary' => $website['home']['description'], 'created_at' => $timestamp, 'updated_at' => $timestamp, 'content_updated_at' => $timestamp]);
    }

    private function render(array $page, array $theme, bool $preview, ?array $website = null, bool $useWebsite = true)
    {
        if ($useWebsite) {
            $website ??= $this->website->published();
        }
        if (! empty($website['theme_id'])) {
            $theme = $this->themes->get($website['theme_id']);
        }
        $translations = [];
        if (! empty($page['translation_group'])) {
            foreach ($this->content->published() as $translation) {
                if (($translation['translation_group'] ?? '') === $page['translation_group'] && $this->seo->indexable($translation)) {
                    $translations[] = $translation;
                }
            }
        }
        $meta = $this->seo->metadata($page, $translations, $website);
        if ($preview) {
            $meta['robots'] = 'noindex,nofollow';
        }
        $nonce = base64_encode(random_bytes(18));

        $extra = [];
        if ($website !== null) {
            $media = [];
            $find = function (array $values) use (&$find, &$media, $preview) {
                foreach ($values as $key => $value) {
                    if (in_array($key, ['image_id', 'logo_id'], true) && is_string($value) && $value !== '') {
                        $record = $this->store->get('website_media', $value);
                        if (($record['status'] ?? '') === 'ready') {
                            $asset = app(WebsiteMedia::class)->present($record);
                            $url = $preview ? $asset['preview_url'] : $asset['url'];
                            $sources = array_map(fn ($source) => ($preview ? $source['preview_url'] : $source['url']).' '.$source['width'].'w', $asset['sources']);
                            $sources[] = $url.' '.$asset['width'].'w';
                            $media[$value] = ['url' => $url, 'width' => $asset['width'], 'height' => $asset['height'], 'alt' => $asset['alt'], 'srcset' => implode(', ', $sources), 'position' => $asset['focal_x'].'% '.$asset['focal_y'].'%'];
                        }
                    } elseif (is_array($value)) {
                        $find($value);
                    }
                }
            };
            $find($website);
            $office = collect($website['offices'])->firstWhere('id', $page['website_office_id'] ?? '');
            $extra = ['website' => $website, 'media' => $media, 'office' => $office, 'fontCss' => app(WebsiteFonts::class)->css($website['brand']['heading_font'], $website['brand']['body_font']), 'publicPages' => array_filter($this->content->published(), fn ($entry) => $this->seo->officeAvailable($entry))];
        }

        return response()->view($website !== null ? 'public.website' : 'public.page', [
            'page' => $page, 'site' => $this->seo->settings()['site'], 'theme' => $theme, 'meta' => $meta,
            'sections' => $website !== null && empty($website['theme_id']) ? [['type' => 'hero'], ['type' => 'content']] : ($theme['templates'][$page['type']] ?? $theme['templates']['page'])['sections'],
            'bodyHtml' => preg_replace('~<(/?)h1(?=[ >])~', '<$1h2', $this->blocks->html($this->content->body($page))), 'preview' => $preview, 'nonce' => $nonce,
        ] + $extra)->withHeaders(['Cache-Control' => $this->cacheControl($preview), 'X-Robots-Tag' => $preview ? 'noindex, nofollow' : $meta['robots'], 'Content-Security-Policy' => "default-src 'self'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'", 'X-Content-Type-Options' => 'nosniff']);
    }

    /** A response that may set a session cookie must never be stored by a shared cache. */
    private function cacheControl(bool $preview = false): string
    {
        return $preview || request()->hasSession() ? 'private, no-store' : 'public, max-age=60';
    }

    public function sitemap()
    {
        $pages = array_filter($this->content->published(), fn ($page) => $this->seo->indexable($page));
        if ($website = $this->website->published()) {
            $pages = array_filter($pages, fn ($page) => $page['slug'] !== 'home');
            $home = $this->websiteHome($website);
            if ($this->seo->indexable($home)) {
                $pages[] = $home;
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
        foreach ($pages as $page) {
            $translations = ! empty($page['translation_group']) ? array_values(array_filter($pages, fn ($other) => ($other['translation_group'] ?? '') === $page['translation_group'])) : [];
            $meta = $this->seo->metadata($page, $translations);
            $xml .= '<url><loc>'.htmlspecialchars($meta['canonical'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc><lastmod>'.htmlspecialchars($page['content_updated_at'] ?? $page['updated_at'], ENT_XML1, 'UTF-8').'</lastmod>';
            foreach ($meta['alternates'] as $language => $url) {
                $xml .= '<xhtml:link rel="alternate" hreflang="'.e($language).'" href="'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';
            }
            $xml .= '</url>';
        }

        return response($xml.'</urlset>')->header('Content-Type', 'application/xml; charset=UTF-8')->header('Cache-Control', $this->cacheControl());
    }

    public function indexNowKey()
    {
        $site = $this->seo->settings()['site'];
        abort_unless(($site['indexnow_enabled'] ?? false) && ! empty($site['indexnow_key']), 404);

        return response($site['indexnow_key'])->header('Content-Type', 'text/plain; charset=UTF-8')->header('X-Robots-Tag', 'noindex')->header('Cache-Control', $this->cacheControl());
    }

    public function sitemapIndex()
    {
        $url = rtrim($this->seo->settings()['site']['url'], '/').'/sitemap.xml';

        return response('<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></sitemap></sitemapindex>')->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots()
    {
        return response("User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /app\nDisallow: /portal\nDisallow: /login\nDisallow: /register\nDisallow: /analyzers/results/\nDisallow: /tools/results/\nDisallow: /tools/verify/\nDisallow: /invite/\nDisallow: /password/\nSitemap: ".rtrim($this->seo->settings()['site']['url'], '/')."/sitemap.xml\n")->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
