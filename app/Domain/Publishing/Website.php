<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\Audit;
use App\Support\Conflict;
use App\Support\PrivateFiles;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class Website
{
    public const TYPES = ['hero', 'practices', 'about', 'commitments', 'people', 'process', 'resources', 'locations', 'contact', 'testimonials', 'results', 'awards', 'faq', 'tools', 'fees', 'content', 'cta'];

    public const FONTS = ['lora', 'libre-baskerville', 'playfair-display', 'merriweather', 'source-serif-4', 'crimson-pro', 'dm-sans', 'inter', 'source-sans-3', 'manrope', 'public-sans', 'noto-sans', 'noto-serif', 'noto-sans-devanagari', 'noto-sans-gurmukhi', 'noto-sans-tamil'];

    public const LAYOUTS = ['standard', 'split', 'centered', 'cards'];

    public const CITATION_STATUSES = ['not_audited', 'match', 'format_only', 'different_office', 'outdated', 'missing', 'duplicate_suspected', 'correction_pending', 'resolved', 'unable_to_verify'];

    public function __construct(private RecordStore $store, private Audit $audit, private ContentRepository $content, private PrivateFiles $files) {}

    public function state(): array
    {
        $record = $this->store->get('settings', 'website-state');
        if (! $record) {
            $record = $this->store->transaction(fn () => $this->store->get('settings', 'website-state') ?? $this->store->create('settings', ['status' => 'draft', 'draft' => $this->defaults(), 'published' => null, 'history' => [], 'approved_hash' => null], 'website-state'));
        }
        $record['catalog'] = ['section_types' => self::TYPES, 'layouts' => self::LAYOUTS, 'fonts' => array_column(app(WebsiteFonts::class)->catalog(), 'id'), 'citation_statuses' => self::CITATION_STATUSES];
        $record['citation_checks'] = $this->citationChecks($record['draft']);
        unset($record['approved_hash']);

        return $record;
    }

    public function published(): ?array
    {
        $document = $this->store->get('settings', 'website-state')['published']['document'] ?? null;

        return $document === null ? null : $this->publicDocument($document);
    }

    public function previewDocument(): array
    {
        return $this->publicDocument($this->state()['draft']);
    }

    public function save(array $document, int $version, string $actor): array
    {
        $this->store->transaction(function () use ($document, $version, $actor) {
            $state = $this->current($version);
            $document = $this->validate($document);
            $state = array_replace($state, ['draft' => $document, 'status' => 'draft', 'approved_hash' => null, 'approved_by' => null, 'approved_at' => null]);
            $this->store->put('settings', 'website-state', $state, $version);
            $this->audit->log($actor, 'website.saved', 'settings', 'website-state', ['version' => $version + 1]);
        });

        return $this->state();
    }

    public function importTheme(string $themeId, int $version, string $actor): array
    {
        return $this->store->transaction(function () use ($themeId, $version, $actor) {
            $state = $this->current($version);
            $theme = app(Themes::class)->get($themeId);
            if (($theme['status'] ?? '') !== 'validated') {
                $this->invalid('theme_id', 'Select a validated theme.');
            }
            $document = $state['draft'];
            $document['theme_id'] = $themeId;
            $tokens = $theme['tokens'];
            foreach (['accent' => 'primary', 'ink' => 'text', 'paper' => 'background', 'muted' => 'muted'] as $source => $destination) {
                if (isset($tokens[$source])) {
                    $document['brand']['colors'][$destination] = $tokens[$source];
                }
            }
            $document['brand']['radius'] = $tokens['radius'] ?? $document['brand']['radius'];
            $document['brand']['width'] = $tokens['content_width'] ?? $document['brand']['width'];
            $document['brand']['heading_font'] = ($tokens['font_family'] ?? 'serif') === 'serif' ? 'lora' : 'dm-sans';
            $document['brand']['body_font'] = 'dm-sans';
            $document['navigation'] = $theme['navigation'] ?? [];
            $result = $this->save($document, $version, $actor);
            $this->audit->log($actor, 'website.theme_imported', 'settings', 'website-state', ['theme_id' => $themeId, 'version' => $result['version']]);

            return $result;
        });
    }

    public function transition(string $action, int $version, string $actor, ?string $revisionId = null): array
    {
        $this->store->transaction(function () use ($action, $version, $actor, $revisionId) {
            $state = $this->current($version);
            $allowed = ['review' => ['draft'], 'approve' => ['in_review'], 'publish' => ['approved'], 'rollback' => ['draft', 'in_review', 'approved', 'published']];
            if (! in_array($state['status'], $allowed[$action] ?? [], true)) {
                $this->invalid('status', 'This action is unavailable at the current review stage.');
            }
            if ($action === 'review') {
                $state['status'] = 'in_review';
            }
            if ($action === 'approve') {
                $this->validate($state['draft'], true);
                $state['status'] = 'approved';
                $state['approved_hash'] = $this->hash($state['draft']);
                $state['approved_by'] = $actor;
                $state['approved_at'] = now()->toISOString();
            }
            if ($action === 'publish') {
                if (! hash_equals($state['approved_hash'] ?? '', $this->hash($state['draft']))) {
                    throw new Conflict('This draft has changed since approval. Submit it for review again.');
                }
                $this->validate($state['draft'], true);
                $state = $this->snapshot($state, $actor, 'published');
            }
            if ($action === 'rollback') {
                $candidates = array_values(array_filter($state['history'], fn ($entry) => $entry['id'] !== ($state['published']['revision_id'] ?? null)));
                $revisionId ??= $candidates[0]['id'] ?? null;
                if (! $revisionId || ! in_array($revisionId, array_column($candidates, 'id'), true)) {
                    $this->invalid('revision_id', 'Choose a retained, previously published revision.');
                }
                $revision = $this->store->get('website_revisions', $revisionId);
                if (! $revision) {
                    $this->invalid('revision_id', 'The retained revision is unavailable.');
                }
                $state['draft'] = $this->validate($revision['document'], true);
                $state['approved_hash'] = $this->hash($state['draft']);
                $state['approved_by'] = $actor;
                $state['approved_at'] = now()->toISOString();
                $state = $this->snapshot($state, $actor, 'rollback');
            }
            $this->store->put('settings', 'website-state', $state, $version);
            $this->audit->log($actor, 'website.'.$action, 'settings', 'website-state', ['version' => $version + 1, 'revision_id' => $state['published']['revision_id'] ?? null]);
        });

        return $this->state();
    }

    public function pagePack(array $input, int $version, string $actor): array
    {
        $written = [];
        try {
            $result = $this->store->transaction(function () use ($input, $version, $actor, &$written) {
                $state = $this->current($version);
                $this->shape($input, ['practices', 'people', 'guides', 'include_tools'], 'page_pack');
                $specs = [];
                foreach (['home' => 'Home', 'about' => 'About', 'practices' => 'Practice areas', 'people' => 'Our people', 'locations' => 'Locations and service delivery', 'contact' => 'Contact', 'resources' => 'Resources', 'privacy' => 'Privacy', 'terms' => 'Terms', 'accessibility' => 'Accessibility', 'professional-disclaimer' => 'Professional disclaimer'] as $slug => $title) {
                    $specs[] = ['title' => $title, 'slug' => $slug, 'type' => match ($slug) {
                        'about' => 'about', 'contact' => 'contact', default => 'page'
                    }];
                }
                foreach (['practices' => 'service', 'people' => 'profile', 'guides' => 'article'] as $key => $type) {
                    $items = $this->listing($input[$key] ?? [], 30, $key);
                    foreach ($items as $i => $item) {
                        $this->shape($item, ['title', 'slug'], "$key.$i");
                        $specs[] = ['title' => $this->text($item['title'] ?? '', 200, "$key.$i.title", true), 'slug' => $this->slug($item['slug'] ?? '', "$key.$i.slug"), 'type' => $type];
                    }
                }
                foreach ($state['draft']['offices'] as $office) {
                    if ($office['kind'] === 'physical') {
                        $specs[] = ['title' => $office['name'], 'slug' => $office['page_slug'], 'type' => 'office', 'website_office_id' => $office['id']];
                    }
                }
                if ($this->boolean($input['include_tools'] ?? false, 'include_tools')) {
                    foreach (Seo::TOOLS as $slug) {
                        $specs[] = ['title' => Str::headline($slug), 'slug' => $slug, 'type' => 'tool', 'tool_slug' => $slug];
                    }
                }
                if (count($specs) > 60) {
                    $this->invalid('page_pack', 'Create at most 60 pages per page pack. Add further pages from the content workspace.');
                }
                $created = [];
                $skipped = [];
                $seen = [];
                foreach ($specs as $spec) {
                    if (isset($seen[$spec['slug']]) || $this->store->get('page_routes', hash('sha256', $spec['slug'])) || $this->store->query('pages', ['slug' => $spec['slug']], 1) || (isset($spec['tool_slug']) && $this->store->get('page_tools', $spec['tool_slug']))) {
                        $skipped[] = ['slug' => $spec['slug'], 'reason' => 'This page or tool already has a reserved URL.'];

                        continue;
                    }
                    $seen[$spec['slug']] = true;
                    $body = 'Add approved content for '.$spec['title'].'. Review the facts, jurisdiction and publication settings before publishing.';
                    $record = $this->content->create('pages', $spec + ['status' => 'draft', 'locale' => 'en', 'summary' => '', 'seo' => [], 'sources' => [], 'website_page_pack' => true], [['type' => 'paragraph', 'content' => $body]], $actor);
                    $written[] = $record['blocks_path'];
                    $created[] = array_intersect_key($record, array_flip(['id', 'title', 'slug', 'type']));
                }
                $this->store->put('settings', 'website-state', $state, $version);
                $this->audit->log($actor, 'website.page_pack', 'settings', 'website-state', ['created' => count($created), 'skipped' => count($skipped)]);

                return compact('created', 'skipped');
            });
        } catch (\Throwable $error) {
            foreach ($written as $path) {
                $this->files->delete($path);
            }
            throw $error;
        }

        return ['data' => $this->state()] + $result;
    }

    private function snapshot(array $state, string $actor, string $action): array
    {
        $publishedAt = now()->toISOString();
        $revision = $this->store->create('website_revisions', ['document' => $state['draft'], 'number' => $state['version'] + 1, 'actor_id' => $actor, 'action' => $action, 'published_at' => $publishedAt]);
        array_unshift($state['history'], ['id' => $revision['id'], 'version' => $state['version'] + 1, 'actor_id' => $actor, 'action' => $action, 'published_at' => $publishedAt]);
        foreach (array_slice($state['history'], 40) as $expired) {
            $this->store->delete('website_revisions', $expired['id']);
        }
        $state['history'] = array_slice($state['history'], 0, 40);
        $state['published'] = ['document' => $state['draft'], 'revision_id' => $revision['id'], 'version' => $state['version'] + 1, 'published_at' => $publishedAt];
        $state['status'] = 'published';

        return $state;
    }

    private function current(int $version): array
    {
        $record = $this->store->get('settings', 'website-state');
        if (! $record || $record['version'] !== $version) {
            throw new Conflict('Website settings changed. Reload the latest revision before saving.');
        }

        return $record;
    }

    private function hash(array $document): string
    {
        return hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function publicDocument(array $document): array
    {
        unset($document['citations']);
        $document['offices'] = array_values(array_filter($document['offices'], fn ($office) => $office['published']));
        $document['home']['sections'] = array_values(array_filter($document['home']['sections'], fn ($section) => $section['visible']));
        foreach ($document['home']['sections'] as &$section) {
            unset($section['proof_note']);
        }

        return $document;
    }

    public function validate(array $document, bool $publishing = false): array
    {
        if (strlen(json_encode($document, JSON_THROW_ON_ERROR)) > 180000) {
            $this->invalid('document', 'Website settings must fit within 180 KB.');
        }
        $this->shape($document, ['schema_version', 'theme_id', 'home', 'organization', 'brand', 'navigation', 'footer', 'offices', 'citations'], 'document');
        if (($document['schema_version'] ?? null) !== 1) {
            $this->invalid('schema_version', 'Use Website API schema version 1.');
        }
        $themeId = $this->themeId($document['theme_id'] ?? null);
        $home = $document['home'] ?? [];
        $this->shape($home, ['title', 'description', 'sections'], 'home');
        $home['title'] = $this->text($home['title'] ?? '', 200, 'home.title', $publishing);
        $home['description'] = $this->text($home['description'] ?? '', 1000, 'home.description');
        $organization = $document['organization'] ?? [];
        $this->shape($organization, ['name', 'email', 'phone', 'description', 'logo_id'], 'organization');
        foreach (['name' => 200, 'email' => 254, 'phone' => 80, 'description' => 2000] as $key => $max) {
            $organization[$key] = $this->text($organization[$key] ?? '', $max, 'organization.'.$key, $publishing && $key === 'name');
        }
        $this->email($organization['email'], 'organization.email');
        $organization['logo_id'] = $this->media($organization['logo_id'] ?? '', 'organization.logo_id');
        $brand = $document['brand'] ?? [];
        $this->shape($brand, ['colors', 'heading_font', 'body_font', 'font_mode', 'radius', 'width'], 'brand');
        $colors = $brand['colors'] ?? [];
        $colorKeys = ['background', 'surface', 'text', 'muted', 'primary', 'primary_text', 'border'];
        $this->shape($colors, $colorKeys, 'brand.colors');
        foreach ($colorKeys as $key) {
            if (! is_string($colors[$key] ?? null) || ! preg_match('/^#[0-9a-fA-F]{6}$/D', $colors[$key])) {
                $this->invalid('brand.colors.'.$key, 'Use a six-digit hex color.');
            }
        }
        $fontIds = array_column(app(WebsiteFonts::class)->catalog(), 'id');
        foreach (['heading_font', 'body_font'] as $key) {
            $this->choice($brand[$key] ?? '', $fontIds, 'brand.'.$key);
        }
        $this->choice($brand['font_mode'] ?? '', ['self-hosted'], 'brand.font_mode');
        $this->integer($brand['radius'] ?? null, 0, 24, 'brand.radius');
        $this->integer($brand['width'] ?? null, 720, 1440, 'brand.width');
        if ($publishing) {
            foreach (['primary_text' => ['primary'], 'text' => ['background', 'surface']] as $foreground => $backgrounds) {
                foreach ($backgrounds as $background) {
                    if ($this->contrast($colors[$foreground], $colors[$background]) < 4.5) {
                        $this->invalid('brand.colors.'.$foreground, 'The '.$foreground.' and '.$background.' colors need a contrast ratio of at least 4.5:1 for readable text.');
                    }
                }
            }
        }
        $navigation = $this->links($document['navigation'] ?? [], 'navigation');
        $footer = $document['footer'] ?? [];
        $this->shape($footer, ['text', 'links'], 'footer');
        $footer = ['text' => $this->text($footer['text'] ?? '', 2000, 'footer.text'), 'links' => $this->links($footer['links'] ?? [], 'footer.links')];
        $offices = [];
        foreach ($this->listing($document['offices'] ?? [], 30, 'offices') as $i => $office) {
            $path = 'offices.'.$i;
            $this->shape($office, ['id', 'name', 'address', 'city', 'region', 'postal_code', 'country', 'phone', 'email', 'hours', 'directions_url', 'kind', 'published', 'verified_at', 'page_slug'], $path);
            $office['id'] = $this->identifier($office['id'] ?? '', "$path.id");
            foreach (['name' => 200, 'address' => 500, 'city' => 150, 'region' => 150, 'postal_code' => 40, 'country' => 100, 'phone' => 80, 'email' => 254, 'hours' => 1000] as $key => $max) {
                $office[$key] = $this->text($office[$key] ?? '', $max, "$path.$key", $key === 'name');
            }
            $this->email($office['email'], "$path.email");
            $office['directions_url'] = $this->url($office['directions_url'] ?? '', "$path.directions_url", true);
            $office['kind'] = $this->choice($office['kind'] ?? 'physical', ['physical', 'remote', 'service_area'], "$path.kind");
            $office['published'] = $this->boolean($office['published'] ?? false, "$path.published");
            $office['verified_at'] = $this->date($office['verified_at'] ?? '', "$path.verified_at");
            $office['page_slug'] = $this->slug($office['page_slug'] ?? 'office-'.Str::slug($office['id']), "$path.page_slug");
            if ($publishing && $office['published'] && ($office['verified_at'] === '' || ($office['kind'] === 'physical' && ($office['address'] === '' || $office['city'] === '' || $office['country'] === '')))) {
                $this->invalid($path, 'Verify published office details and supply the real physical address, city and country.');
            }
            $offices[] = $office;
        }
        $this->unique($offices, 'id', 'offices');
        $this->unique($offices, 'page_slug', 'offices');
        $officeIds = array_column($offices, 'id');
        $sections = [];
        foreach ($this->listing($home['sections'] ?? [], 30, 'home.sections') as $i => $section) {
            $path = 'home.sections.'.$i;
            $this->shape($section, ['id', 'type', 'heading', 'text', 'image_id', 'image_alt', 'button_label', 'button_url', 'secondary_label', 'secondary_url', 'visible', 'layout', 'items', 'office_ids', 'proof_source_url', 'proof_note'], $path);
            $section['id'] = $this->identifier($section['id'] ?? '', "$path.id");
            $section['type'] = $this->choice($section['type'] ?? '', self::TYPES, "$path.type");
            foreach (['heading' => 200, 'text' => 5000, 'image_alt' => 300, 'button_label' => 80, 'secondary_label' => 80, 'proof_note' => 2000] as $key => $max) {
                $section[$key] = $this->text($section[$key] ?? '', $max, "$path.$key");
            }
            foreach (['button_url', 'secondary_url', 'proof_source_url'] as $key) {
                $section[$key] = $this->url($section[$key] ?? '', "$path.$key", $key === 'proof_source_url');
            }
            $section['image_id'] = $this->media($section['image_id'] ?? '', "$path.image_id");
            $section['visible'] = $this->boolean($section['visible'] ?? true, "$path.visible");
            $section['layout'] = $this->choice($section['layout'] ?? 'standard', self::LAYOUTS, "$path.layout");
            $section['office_ids'] = $this->listing($section['office_ids'] ?? [], 30, "$path.office_ids");
            foreach ($section['office_ids'] as $officeId) {
                if (! is_string($officeId) || ! in_array($officeId, $officeIds, true)) {
                    $this->invalid("$path.office_ids", 'Select an office in the office registry.');
                }
            }
            $items = [];
            foreach ($this->listing($section['items'] ?? [], 20, "$path.items") as $j => $item) {
                $itemPath = "$path.items.$j";
                $this->shape($item, ['id', 'title', 'text', 'image_id', 'image_alt', 'url'], $itemPath);
                $items[] = ['id' => $this->identifier($item['id'] ?? '', "$itemPath.id"), 'title' => $this->text($item['title'] ?? '', 200, "$itemPath.title"), 'text' => $this->text($item['text'] ?? '', 2000, "$itemPath.text"), 'image_id' => $this->media($item['image_id'] ?? '', "$itemPath.image_id"), 'image_alt' => $this->text($item['image_alt'] ?? '', 300, "$itemPath.image_alt"), 'url' => $this->url($item['url'] ?? '', "$itemPath.url")];
            }
            $this->unique($items, 'id', "$path.items");
            $section['items'] = $items;
            if ($publishing && $section['visible'] && in_array($section['type'], ['testimonials', 'results', 'awards'], true) && ($section['proof_source_url'] === '' || $section['proof_note'] === '')) {
                $this->invalid($path, 'Public proof requires an authentic source URL and a reviewer note confirming permission and jurisdiction review.');
            }
            if ($publishing && $section['visible'] && $section['image_id'] !== '' && $section['image_alt'] === '') {
                $this->invalid("$path.image_alt", 'Describe the published image for screen readers.');
            }
            foreach ($items as $j => $item) {
                if ($publishing && $section['visible'] && $item['image_id'] !== '' && $item['image_alt'] === '') {
                    $this->invalid("$path.items.$j.image_alt", 'Describe the published image for screen readers.');
                }
            }
            $sections[] = $section;
        }
        $this->unique($sections, 'id', 'home.sections');
        if ($publishing && count(array_filter($sections, fn ($s) => $s['visible'])) === 0) {
            $this->invalid('home.sections', 'Publish at least one visible homepage section.');
        }
        $home['sections'] = $sections;
        $citations = [];
        foreach ($this->listing($document['citations'] ?? [], 100, 'citations') as $i => $citation) {
            $path = 'citations.'.$i;
            $this->shape($citation, ['id', 'office_id', 'provider', 'url', 'name', 'address', 'phone', 'status', 'observed_at', 'notes'], $path);
            $citation['id'] = $this->identifier($citation['id'] ?? '', "$path.id");
            $citation['office_id'] = $this->choice($citation['office_id'] ?? '', $officeIds, "$path.office_id");
            foreach (['provider' => 100, 'name' => 200, 'address' => 600, 'phone' => 80, 'notes' => 2000] as $key => $max) {
                $citation[$key] = $this->text($citation[$key] ?? '', $max, "$path.$key");
            }
            $citation['url'] = $this->url($citation['url'] ?? '', "$path.url", true);
            $citation['status'] = $this->choice($citation['status'] ?? 'not_audited', self::CITATION_STATUSES, "$path.status");
            $citation['observed_at'] = $this->date($citation['observed_at'] ?? '', "$path.observed_at");
            $citations[] = $citation;
        }
        $this->unique($citations, 'id', 'citations');

        return ['schema_version' => 1, 'theme_id' => $themeId, 'home' => $home, 'organization' => $organization, 'brand' => $brand, 'navigation' => $navigation, 'footer' => $footer, 'offices' => $offices, 'citations' => $citations];
    }

    private function defaults(): array
    {
        $site = app(Seo::class)->settings()['site'];
        $theme = app(Themes::class)->active();
        $tokens = $theme['tokens'] ?? [];
        $headings = ['hero' => 'A clear next step for your legal matter', 'practices' => 'How we can help', 'about' => 'Meet our firm', 'commitments' => 'What to expect', 'people' => 'Our people', 'process' => 'Getting started', 'resources' => 'Useful resources', 'locations' => 'Where we work', 'contact' => 'Speak with our team'];
        $sections = [];
        foreach ($headings as $type => $heading) {
            $sections[] = ['id' => $type, 'type' => $type, 'heading' => $heading, 'text' => $type === 'hero' ? 'Tell us what you need help with. Our team will explain the next steps.' : '', 'image_id' => '', 'image_alt' => '', 'button_label' => $type === 'hero' ? 'Contact our team' : '', 'button_url' => $type === 'hero' ? '#contact' : '', 'secondary_label' => '', 'secondary_url' => '', 'visible' => in_array($type, ['hero', 'contact'], true), 'layout' => $type === 'hero' ? 'split' : 'standard', 'items' => [], 'office_ids' => [], 'proof_source_url' => '', 'proof_note' => ''];
        }
        foreach ($theme['templates']['page']['sections'] ?? [] as $legacy) {
            foreach ($sections as &$section) {
                if ($section['type'] === ($legacy['type'] ?? '') && in_array($section['type'], ['hero', 'contact'], true)) {
                    foreach (['heading', 'text', 'button_label', 'button_url'] as $key) {
                        if (isset($legacy[$key])) {
                            $section[$key] = $legacy[$key];
                        }
                    }
                }
            }
            unset($section);
        }
        $home = $this->store->query('pages', ['slug' => 'home'], 1)[0] ?? null;
        if ($home) {
            $home = $home['published_snapshot'] ?? $home;
            $plain = app(BlockDocument::class)->plainText($this->content->body($home));
            if ($plain !== '') {
                $section = $sections[2];
                $section['id'] = 'legacy-content';
                $section['visible'] = true;
                $section['type'] = 'content';
                $section['heading'] = $home['title'];
                $section['text'] = '';
                array_splice($sections, 1, 0, [$section]);
            }
        }
        $offices = [];
        if (! empty($site['address'])) {
            $offices[] = ['id' => 'primary', 'name' => $site['name'], 'address' => $site['address'], 'city' => '', 'region' => '', 'postal_code' => '', 'country' => '', 'phone' => $site['phone'] ?? '', 'email' => $site['email'] ?? '', 'hours' => '', 'directions_url' => '', 'kind' => 'physical', 'published' => false, 'verified_at' => '', 'page_slug' => 'primary-office'];
        }

        return ['schema_version' => 1, 'theme_id' => $theme['id'], 'home' => ['title' => $home['title'] ?? $site['name'], 'description' => $home['summary'] ?? $site['seo']['description'] ?? '', 'sections' => $sections], 'organization' => ['name' => $site['name'], 'email' => $site['email'] ?? '', 'phone' => $site['phone'] ?? '', 'description' => '', 'logo_id' => ''], 'brand' => ['colors' => ['background' => $tokens['paper'] ?? '#fbfaf6', 'surface' => '#ffffff', 'text' => $tokens['ink'] ?? '#202622', 'muted' => $tokens['muted'] ?? '#6c726c', 'primary' => $tokens['accent'] ?? '#285448', 'primary_text' => '#ffffff', 'border' => '#deded8'], 'heading_font' => ($tokens['font_family'] ?? 'serif') === 'serif' ? 'lora' : 'dm-sans', 'body_font' => 'dm-sans', 'font_mode' => 'self-hosted', 'radius' => $tokens['radius'] ?? 4, 'width' => $tokens['content_width'] ?? 1120], 'navigation' => $theme['navigation'] ?? [['label' => 'Home', 'url' => '/']], 'footer' => ['text' => '', 'links' => []], 'offices' => $offices, 'citations' => []];
    }

    private function citationChecks(array $document): array
    {
        $offices = array_column($document['offices'], null, 'id');
        $normalize = fn ($value) => mb_strtolower(preg_replace('/[\s\p{P}]+/u', '', $value));

        return array_map(function ($citation) use ($offices, $normalize) {
            $office = $offices[$citation['office_id']] ?? [];
            $differences = [];
            foreach (['name', 'address', 'phone'] as $field) {
                $expected = $office[$field] ?? '';
                if ($field === 'address') {
                    $expected = implode(', ', array_filter(array_map(fn ($key) => $office[$key] ?? '', ['address', 'city', 'region', 'postal_code', 'country'])));
                }
                if ($normalize($citation[$field] ?? '') !== $normalize($expected)) {
                    $differences[] = $field;
                }
            }

            return ['id' => $citation['id'], 'office_id' => $citation['office_id'], 'differences' => $differences, 'status' => $citation['status']];
        }, $document['citations']);
    }

    private function shape(mixed $value, array $keys, string $path): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value)) || array_diff(array_keys($value), $keys)) {
            $this->invalid($path, 'Use only the supported fields for this object.');
        }
    }

    private function listing(mixed $value, int $max, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            $this->invalid($path, 'Use a list containing at most '.$max.' items.');
        }

        return $value;
    }

    private function text(mixed $value, int $max, string $path, bool $required = false): string
    {
        $value ??= '';
        if (! is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value) || ($required && trim($value) === '')) {
            $this->invalid($path, 'Provide '.($required ? 'non-empty ' : '').'text of at most '.$max.' characters.');
        }

        return trim($value);
    }

    private function identifier(mixed $value, string $path): string
    {
        if (! is_string($value) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$/D', $value)) {
            $this->invalid($path, 'Provide a valid identifier.');
        }

        return $value;
    }

    private function slug(mixed $value, string $path): string
    {
        if (! is_string($value) || strlen($value) > 120 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value)) {
            $this->invalid($path, 'Use a lowercase URL slug with hyphens.');
        }

        return $value;
    }

    private function choice(mixed $value, array $choices, string $path): string
    {
        if (! is_string($value) || ! in_array($value, $choices, true)) {
            $this->invalid($path, 'Select a supported option.');
        }

        return $value;
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            $this->invalid($path, 'Use true or false.');
        }

        return $value;
    }

    private function integer(mixed $value, int $min, int $max, string $path): void
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $this->invalid($path, 'Use a whole number between '.$min.' and '.$max.'.');
        }
    }

    private function date(mixed $value, string $path): string
    {
        $value = $this->text($value, 10, $path);
        $date = $value === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($value !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || ! $date || $date->format('Y-m-d') !== $value || $value > now()->toDateString())) {
            $this->invalid($path, 'Use a valid observation date that is not in the future.');
        }

        return $value;
    }

    private function email(string $value, string $path): void
    {
        if ($value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->invalid($path, 'Use a valid email address.');
        }
    }

    private function url(mixed $value, string $path, bool $webOnly = false): string
    {
        $value = $this->text($value, 2000, $path);
        if ($value === '') {
            return '';
        }
        $decoded = rawurldecode($value);
        if (preg_match('/[\x00-\x20<>"\\\\]/', $decoded)) {
            $this->invalid($path, 'Use a safe public URL.');
        }
        if (! $webOnly && preg_match('~^#[a-zA-Z][a-zA-Z0-9_-]*$~D', $value)) {
            return $value;
        }
        if (! $webOnly && str_starts_with($decoded, '/') && ! str_starts_with($decoded, '//') && ! str_contains($decoded, '..')) {
            if (! preg_match('~^/(?:$|p/[a-z0-9-]+$|tools/[a-z0-9-]+$|(?:login|register|contact-request)$|website/media/[a-zA-Z0-9_-]+$)~D', (string) parse_url($decoded, PHP_URL_PATH))) {
                $this->invalid($path, 'Local links must point to a public page, tool, media item or sign-in page.');
            }

            return $value;
        }
        if (! $webOnly && str_starts_with($value, 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL)) {
            return $value;
        }
        if (! $webOnly && preg_match('/^tel:\+?[0-9().-]{3,30}$/D', $value)) {
            return $value;
        }
        if (! filter_var($value, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) || parse_url($value, PHP_URL_USER) || parse_url($value, PHP_URL_PASS)) {
            $this->invalid($path, 'Use a public HTTP(S), page, telephone or email link.');
        }

        return $value;
    }

    private function themeId(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $id = $this->identifier($id, 'theme_id');
        try {
            $theme = app(Themes::class)->get($id);
        } catch (NotFoundHttpException) {
            $this->invalid('theme_id', 'Select a validated theme from the installed library.');
        }
        if (($theme['status'] ?? '') !== 'validated') {
            $this->invalid('theme_id', 'Select a validated theme from the installed library.');
        }

        return $id;
    }

    private function media(mixed $id, string $path): string
    {
        if ($id === null || $id === '') {
            return '';
        }
        $id = $this->identifier($id, $path);
        $media = $this->store->get('website_media', $id);
        if (! $media || ($media['status'] ?? '') !== 'ready') {
            $this->invalid($path, 'Choose a scanned, ready public media item.');
        }

        return $id;
    }

    private function contrast(string $foreground, string $background): float
    {
        $first = $this->luminance($foreground);
        $second = $this->luminance($background);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private function luminance(string $color): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function links(mixed $value, string $path): array
    {
        $links = [];
        foreach ($this->listing($value, 16, $path) as $i => $link) {
            $this->shape($link, ['label', 'url'], "$path.$i");
            $links[] = ['label' => $this->text($link['label'] ?? '', 80, "$path.$i.label", true), 'url' => $this->url($link['url'] ?? '', "$path.$i.url")];
            if ($links[$i]['url'] === '') {
                $this->invalid("$path.$i.url", 'Provide a destination.');
            }
        }

        return $links;
    }

    private function unique(array $items, string $key, string $path): void
    {
        if (count(array_column($items, $key)) !== count(array_unique(array_column($items, $key)))) {
            $this->invalid($path, 'Identifiers and URL slugs must be unique.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
