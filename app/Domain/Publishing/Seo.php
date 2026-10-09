<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\AiGateway;
use App\Support\Settings;
use Illuminate\Validation\ValidationException;

final class Seo
{
    public const TOOLS = ['notice-explainer', 'consultation-preparation', 'document-completeness'];

    public const SCHEMAS = ['WebPage', 'AboutPage', 'ContactPage', 'ProfilePage', 'Article', 'BlogPosting', 'Service', 'LegalService', 'SoftwareApplication', 'FAQPage', 'HowTo'];

    public const FIELDS = ['title', 'description', 'canonical', 'robots', 'og_title', 'og_description', 'og_image', 'og_image_alt', 'og_type', 'twitter_card', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_alt', 'schemas', 'advanced_schema'];

    public function __construct(private RecordStore $store) {}

    public function settings(): array
    {
        $stored = $this->store->get('settings', 'publishing') ?? [];

        $settings = array_replace_recursive([
            'site' => ['name' => config('app.name', 'Law Firm'), 'url' => rtrim(config('app.url', 'http://localhost'), '/'), 'locale' => 'en', 'email' => '', 'phone' => '', 'address' => '', 'seo' => []],
            'types' => [],
        ], $stored);

        return $this->websiteSettings($settings, $this->store->get('settings', 'website-state')['published']['document'] ?? null);
    }

    private function websiteSettings(array $settings, ?array $website): array
    {
        if ($website === null) {
            return $settings;
        }
        foreach (['name', 'email', 'phone'] as $key) {
            $settings['site'][$key] = $website['organization'][$key] ?? '';
        }
        $offices = array_values(array_filter($website['offices'] ?? [], fn ($office) => ($office['published'] ?? false) && ($office['kind'] ?? '') === 'physical'));
        $settings['site']['address'] = isset($offices[0]) ? implode(', ', array_filter(array_intersect_key($offices[0], array_flip(['address', 'city', 'region', 'postal_code', 'country'])))) : '';

        return $settings;
    }

    public function validate(array $seo): array
    {
        foreach ($seo as $key => $value) {
            if (! in_array($key, self::FIELDS, true)) {
                $this->invalid('Unsupported SEO field: '.$key);
            }
            if (in_array($key, ['schemas', 'advanced_schema'], true)) {
                continue;
            }
            if ($value !== null && (! is_string($value) || mb_strlen($value) > 2000)) {
                $this->invalid('Invalid SEO value.');
            }
        }
        foreach (['canonical', 'og_image', 'twitter_image'] as $key) {
            if (! empty($seo[$key]) && ! $this->webUrl($seo[$key])) {
                $this->invalid('Use a complete HTTP(S) URL for '.$key.'.');
            }
        }
        if (isset($seo['robots']) && ! in_array($seo['robots'], ['index,follow', 'noindex,follow', 'noindex,nofollow'], true)) {
            $this->invalid('Invalid indexing setting.');
        }
        if (isset($seo['og_type']) && ! in_array($seo['og_type'], ['website', 'article', 'profile'], true)) {
            $this->invalid('Invalid Open Graph type.');
        }
        if (isset($seo['twitter_card']) && ! in_array($seo['twitter_card'], ['summary', 'summary_large_image'], true)) {
            $this->invalid('Invalid X card type.');
        }
        if (isset($seo['schemas'])) {
            if (! is_array($seo['schemas']) || ! array_is_list($seo['schemas']) || count($seo['schemas']) > 5 || array_diff($seo['schemas'], self::SCHEMAS)) {
                $this->invalid('Unsupported schema type.');
            }
        }
        if (! empty($seo['advanced_schema'])) {
            $this->validateAdvanced($seo['advanced_schema']);
        }

        foreach (array_intersect($seo['schemas'] ?? [], ['FAQPage', 'HowTo']) as $type) {
            $found = false;
            $find = function (array $node) use (&$find, &$found, $type) {
                if (in_array($type, (array) ($node['@type'] ?? []), true)) {
                    $found = true;
                } foreach ($node as $value) {
                    if (is_array($value)) {
                        $find($value);
                    }
                }
            };
            $find($seo['advanced_schema'] ?? []);
            if (! $found) {
                $this->invalid($type.' requires complete advanced schema describing visible page content.');
            }
        }

        return $seo;
    }

    public function validateAdvanced(mixed $schema): void
    {
        if (! is_array($schema) || strlen(json_encode($schema)) > 50_000 || ($schema['@context'] ?? 'https://schema.org') !== 'https://schema.org') {
            $this->invalid('Invalid advanced schema context or size.');
        }
        $allowedTypes = array_merge(self::SCHEMAS, ['Person', 'Organization', 'WebSite', 'BreadcrumbList', 'ListItem', 'PostalAddress', 'Question', 'Answer', 'HowToStep']);
        $walk = function ($node, $depth = 0) use (&$walk, $allowedTypes) {
            if ($depth > 10) {
                $this->invalid('Advanced schema is too deeply nested.');
            }
            $types = (array) ($node['@type'] ?? []);
            if (in_array('FAQPage', $types, true) && (! is_array($node['mainEntity'] ?? null) || empty($node['mainEntity']))) {
                $this->invalid('FAQ schema requires visible questions and answers.');
            }
            if (in_array('Question', $types, true) && (! is_string($node['name'] ?? null) || ! is_array($node['acceptedAnswer'] ?? null))) {
                $this->invalid('Questions require a name and accepted answer.');
            }
            if (in_array('Answer', $types, true) && (! is_string($node['text'] ?? null) || trim($node['text']) === '')) {
                $this->invalid('Answers require visible text.');
            }
            if (in_array('HowTo', $types, true) && (! is_array($node['step'] ?? null) || empty($node['step']))) {
                $this->invalid('HowTo schema requires visible steps.');
            }
            if (in_array('HowToStep', $types, true) && (! is_string($node['text'] ?? null) || trim($node['text']) === '')) {
                $this->invalid('HowTo steps require visible text.');
            }
            foreach ($node as $key => $value) {
                if (in_array(strtolower((string) $key), ['aggregaterating', 'review', 'reviews', 'rating', 'award', 'awards', 'price', 'offers', 'script', 'html'], true)) {
                    $this->invalid('Ratings, promotional claims, prices and executable content are not accepted in advanced schema.');
                }
                if (is_string($key) && ! preg_match('/^[@a-zA-Z][a-zA-Z0-9_]*$/', $key)) {
                    $this->invalid('Invalid schema property.');
                }
                if ($key === '@context' && $value !== 'https://schema.org') {
                    $this->invalid('Remote schema contexts are not accepted.');
                }
                if ($key === '@type') {
                    foreach ((array) $value as $type) {
                        if (! is_string($type) || ! in_array($type, $allowedTypes, true)) {
                            $this->invalid('Unsupported advanced schema type.');
                        }
                    }
                }
                if (in_array($key, ['@id', 'url', 'image', 'sameAs'], true)) {
                    foreach ((array) $value as $url) {
                        if (! is_string($url) || ! $this->webUrl($url)) {
                            $this->invalid('Schema URL must be HTTP(S).');
                        }
                    }
                }
                if (is_array($value)) {
                    $walk($value, $depth + 1);
                } elseif (! is_scalar($value) && $value !== null) {
                    $this->invalid('Invalid schema value.');
                }
            }
        };
        $walk($schema);
    }

    public function metadata(array $page, array $translations = [], ?array $website = null): array
    {
        $website ??= $this->store->get('settings', 'website-state')['published']['document'] ?? null;
        $settings = $this->websiteSettings($this->settings(), $website);
        $site = $settings['site'];
        $seo = array_replace($site['seo'] ?? [], $settings['types'][$page['type'] ?? 'page'] ?? [], array_filter($page['seo'] ?? [], fn ($value) => $value !== null && $value !== ''));
        $base = rtrim($site['url'], '/');
        $url = $base.$this->path($page);
        $canonical = $seo['canonical'] ?? $url;
        $title = $seo['title'] ?? (($page['title'] ?? 'Home').' | '.$site['name']);
        $description = $seo['description'] ?? $page['summary'] ?? '';
        $type = $page['type'] ?? 'page';
        $schemaType = match ($type) {
            'article' => 'Article', 'service' => 'Service', 'profile' => 'ProfilePage', 'office' => 'LegalService', 'tool' => 'SoftwareApplication', 'about' => 'AboutPage', 'contact' => 'ContactPage', default => 'WebPage'
        };
        $types = $seo['schemas'] ?? [$schemaType];
        $firm = ['@type' => 'LegalService', '@id' => $base.'/#firm', 'name' => $site['name'], 'url' => $base.'/'];
        foreach (['email', 'telephone' => 'phone', 'address'] as $key => $field) {
            $key = is_int($key) ? $field : $key;
            if (! empty($site[$field])) {
                $firm[$key] = $site[$field];
            }
        }
        $graph = [$firm, ['@type' => 'WebSite', '@id' => $base.'/#website', 'url' => $base.'/', 'name' => $site['name'], 'publisher' => ['@id' => $firm['@id']]], [
            '@type' => array_values(array_unique(array_merge(['WebPage'], array_intersect($types, ['WebPage', 'AboutPage', 'ContactPage', 'ProfilePage'])))),
            '@id' => $url.'#webpage', 'url' => $url, 'name' => $title, 'description' => $description,
            'inLanguage' => $page['locale'] ?? $site['locale'], 'isPartOf' => ['@id' => $base.'/#website'], 'publisher' => ['@id' => $firm['@id']],
        ]];
        foreach ($website['offices'] ?? [] as $office) {
            if (! ($office['published'] ?? false)) {
                continue;
            }
            $node = ['@type' => 'LegalService', '@id' => $base.'/#office-'.$office['id'], 'name' => $office['name'], 'parentOrganization' => ['@id' => $firm['@id']]];
            if (! empty($office['phone'])) {
                $node['telephone'] = $office['phone'];
            }
            if (($office['kind'] ?? '') === 'physical') {
                $node['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $office['address'], 'addressLocality' => $office['city'], 'addressRegion' => $office['region'], 'postalCode' => $office['postal_code'], 'addressCountry' => $office['country']];
            }
            if (! empty($office['page_slug'])) {
                $route = $this->store->get('page_routes', hash('sha256', $office['page_slug']));
                $officePage = $route ? ($this->store->get('pages', $route['page_id'])['published_snapshot'] ?? null) : null;
                if (($route['status'] ?? '') === 'published' && ($officePage['website_office_id'] ?? null) === $office['id']) {
                    $node['url'] = $base.$this->path($officePage);
                }
            }
            if (($page['website_office_id'] ?? null) === $office['id']) {
                $graph[2]['mainEntity'] = ['@id' => $node['@id']];
            }
            $graph[] = $node;
        }
        if (! empty($page['author_name'])) {
            $author = ['@type' => 'Person', '@id' => $url.'#author', 'name' => $page['author_name']];
            $graph[] = $author;
            $graph[2]['author'] = ['@id' => $author['@id']];
        }
        foreach (array_diff($types, ['WebPage', 'AboutPage', 'ContactPage', 'ProfilePage', 'FAQPage', 'HowTo']) as $t) {
            if ($t === 'LegalService' && ! empty($page['website_office_id'])) {
                continue;
            }
            $node = ['@type' => $t, '@id' => $url.'#'.strtolower($t), 'name' => $page['title'], 'url' => $url, 'description' => $description];
            if (in_array($t, ['Article', 'BlogPosting'], true)) {
                $node += ['headline' => $page['title'], 'datePublished' => $page['published_at'] ?? $page['created_at'], 'dateModified' => $page['content_updated_at'] ?? $page['updated_at'], 'publisher' => ['@id' => $firm['@id']], 'mainEntityOfPage' => ['@id' => $url.'#webpage']];
                if (isset($author)) {
                    $node['author'] = ['@id' => $author['@id']];
                }
            }
            if ($t === 'Service') {
                $node['provider'] = ['@id' => $firm['@id']];
            }
            if ($t === 'SoftwareApplication') {
                $node += ['applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web'];
            }
            $graph[] = $node;
        }
        if (($page['slug'] ?? 'home') !== 'home') {
            $graph[] = ['@type' => 'BreadcrumbList', '@id' => $url.'#breadcrumb', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $base.'/'], ['@type' => 'ListItem', 'position' => 2, 'name' => $page['title'], 'item' => $url]]];
        }
        if (! empty($seo['advanced_schema'])) {
            $extra = $seo['advanced_schema'];
            unset($extra['@context']);
            $graph = array_merge($graph, $extra['@graph'] ?? [$extra]);
        }
        $alternates = [];
        foreach ($translations as $translation) {
            $alternates[$translation['locale']] = $base.$this->path($translation);
        }

        return [
            'title' => $title, 'description' => $description, 'canonical' => $canonical, 'robots' => $seo['robots'] ?? 'index,follow',
            'og_title' => $seo['og_title'] ?? $title, 'og_description' => $seo['og_description'] ?? $description,
            'og_type' => $seo['og_type'] ?? ($type === 'article' ? 'article' : 'website'), 'og_url' => $canonical,
            'og_image' => $seo['og_image'] ?? '', 'og_image_alt' => $seo['og_image_alt'] ?? '', 'site_name' => $site['name'], 'og_locale' => str_replace('-', '_', $page['locale'] ?? 'en'),
            'twitter_card' => $seo['twitter_card'] ?? 'summary', 'twitter_title' => $seo['twitter_title'] ?? $seo['og_title'] ?? $title,
            'twitter_description' => $seo['twitter_description'] ?? $seo['og_description'] ?? $description,
            'twitter_image' => $seo['twitter_image'] ?? $seo['og_image'] ?? '',
            'twitter_image_alt' => $seo['twitter_image_alt'] ?? $seo['og_image_alt'] ?? '',
            'schema' => ['@context' => 'https://schema.org', '@graph' => $graph],
            'schema_json' => json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'alternates' => $alternates, 'resolved' => $seo,
            'inherited' => array_values(array_diff(array_keys($seo), array_keys(array_filter($page['seo'] ?? [], fn ($v) => $v !== null && $v !== '')))),
        ];
    }

    public function path(array $page): string
    {
        if (($page['id'] ?? '') === 'website-enquiry') {
            return '/contact-request';
        }
        if (($page['type'] ?? '') === 'tool' && in_array($page['tool_slug'] ?? '', self::TOOLS, true)) {
            return '/tools/'.$page['tool_slug'];
        }

        return ($page['slug'] ?? '') === 'home' ? '/' : '/p/'.$page['slug'];
    }

    public function indexable(array $page): bool
    {
        if (! $this->officeAvailable($page)) {
            return false;
        }
        $metadata = $this->metadata($page);
        if (! empty($page['tool_slug']) && ! $this->toolsEnabled()) {
            return false;
        }

        return $metadata['robots'] === 'index,follow' && $metadata['canonical'] === rtrim($this->settings()['site']['url'], '/').$this->path($page);
    }

    public function officeAvailable(array $page): bool
    {
        if (empty($page['website_office_id'])) {
            return true;
        }
        $offices = $this->store->get('settings', 'website-state')['published']['document']['offices'] ?? [];

        return collect($offices)->contains(fn ($office) => $office['id'] === $page['website_office_id'] && $office['published']);
    }

    public function assertVisibleSchema(array $page, array $blocks): void
    {
        $schema = $this->metadata($page)['resolved']['advanced_schema'] ?? [];
        $visible = mb_strtolower(preg_replace('/\s+/', ' ', $page['title'].' '.($page['summary'] ?? '').' '.app(BlockDocument::class)->plainText($blocks)));
        $walk = function (array $node) use (&$walk, $visible) {
            $types = (array) ($node['@type'] ?? []);
            foreach (['Question' => 'name', 'Answer' => 'text', 'HowToStep' => 'text'] as $type => $field) {
                if (in_array($type, $types, true)) {
                    $text = mb_strtolower(preg_replace('/\s+/', ' ', strip_tags((string) ($node[$field] ?? ''))));
                    if ($text === '' || ! str_contains($visible, $text)) {
                        $this->invalid('Structured questions, answers and steps must appear in the visible page content.');
                    }
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($schema);
    }

    public function toolsEnabled(): bool
    {
        return app(AiGateway::class)->configured() && (app(Settings::class)->get('ai')['public_tools_approved'] ?? false) && (bool) config('crm.scanner.url') && (bool) config('services.turnstile.secret');
    }

    public function publishedTool(string $tool): ?array
    {
        foreach ($this->store->query('pages', [], 10000) as $record) {
            $page = $record['published_snapshot'] ?? null;
            if ($page && ($page['type'] ?? '') === 'tool' && ($page['tool_slug'] ?? '') === $tool) {
                return $page;
            }
        }

        return null;
    }

    private function webUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) && ! parse_url($url, PHP_URL_USER) && ! preg_match('/[\x00-\x20<>]/', $url);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['seo' => $message]);
    }
}
