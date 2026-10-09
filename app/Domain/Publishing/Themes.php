<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\Audit;
use App\Support\PrivateFiles;
use App\Support\UploadScanner;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class Themes
{
    private const MAX_ZIP = 25 * 1024 * 1024;

    private const MAX_EXPANDED = 100 * 1024 * 1024;

    private const SECTIONS = ['hero', 'content', 'cta', 'contact', 'columns'];

    public function __construct(private RecordStore $store, private PrivateFiles $files, private Audit $audit, private UploadScanner $scanner) {}

    public function builtins(): array
    {
        return array_map(function ($name) {
            $theme = json_decode(file_get_contents(resource_path('themes/'.$name.'/theme.json')), true, 512, JSON_THROW_ON_ERROR);
            $theme['id'] = 'builtin-'.$name;
            $theme['builtin'] = true;
            $theme['assets'] = [];
            $theme['status'] = 'validated';

            return $theme;
        }, ['chambers', 'counsel']);
    }

    public function all(): array
    {
        $active = $this->active()['id'];

        return array_map(fn ($theme) => $this->response($theme) + ['active' => $theme['id'] === $active], array_merge($this->builtins(), $this->store->query('themes', [], 1000)));
    }

    public function get(string $id): array
    {
        foreach ($this->builtins() as $theme) {
            if ($theme['id'] === $id) {
                return $theme;
            }
        }
        $theme = $this->store->get('themes', $id);
        abort_unless($theme !== null, 404);

        return $theme;
    }

    public function active(): array
    {
        return $this->get(($this->store->get('settings', 'theme-state')['active_id'] ?? 'builtin-chambers'));
    }

    public function activate(string $id, string $actor): array
    {
        $theme = $this->get($id);

        return $this->store->transaction(function () use ($theme, $actor) {
            $state = $this->store->get('settings', 'theme-state');
            $previous = $state['active_id'] ?? 'builtin-chambers';
            $history = $state['history'] ?? [];
            if ($previous !== $theme['id']) {
                $history[] = $previous;
            }
            $data = ['active_id' => $theme['id'], 'history' => array_slice($history, -20)];
            $state ? $this->store->put('settings', 'theme-state', $data, $state['version']) : $this->store->create('settings', $data, 'theme-state');
            $this->audit->log($actor, 'themes.activated', 'themes', $theme['id'], ['previous_id' => $previous]);

            return $this->response($theme) + ['active' => true];
        });
    }

    public function rollback(string $actor): array
    {
        return $this->store->transaction(function () use ($actor) {
            $state = $this->store->get('settings', 'theme-state');
            $history = $state['history'] ?? [];
            if (! $history) {
                $this->invalid('No previous theme activation is available.');
            }
            $previous = array_pop($history);
            $theme = $this->get($previous);
            $this->store->put('settings', 'theme-state', ['active_id' => $previous, 'history' => $history], $state['version']);
            $this->audit->log($actor, 'themes.rolled_back', 'themes', $previous);

            return $this->response($theme) + ['active' => true];
        });
    }

    public function design(array $input, string $actor): array
    {
        $manifest = ['schema_version' => 1, 'name' => $input['name'], 'theme_version' => $input['theme_version'] ?? '1.0.0', 'renderer' => 1, 'author' => $input['author'] ?? '', 'license' => $input['license'] ?? 'Private', 'supported_blocks' => BlockDocument::TYPES];
        $tokens = $this->validateTokens($input['tokens'] ?? []);
        $templates = $this->validateTemplates($input['templates'] ?? []);
        $navigation = $this->validateNavigation($input['navigation'] ?? []);
        $record = $this->store->transaction(function () use ($manifest, $tokens, $templates, $navigation, $actor) {
            $theme = $this->store->create('themes', $manifest + ['tokens' => $tokens, 'templates' => $templates, 'navigation' => $navigation, 'assets' => [], 'status' => 'validated', 'owner_id' => $actor, 'builtin' => false]);
            $this->audit->log($actor, 'themes.designed', 'themes', $theme['id']);

            return $theme;
        });

        return $this->response($record);
    }

    public function import(string $zipPath, string $actor): array
    {
        if (! is_file($zipPath) || filesize($zipPath) > self::MAX_ZIP) {
            $this->invalid('Theme ZIP must be 25 MB or smaller.');
        }
        $path = $this->files->write(file_get_contents($zipPath), 'theme_quarantine');
        $upload = $this->store->create('theme_uploads', ['owner_id' => $actor, 'status' => 'quarantined', 'path' => $path, 'name' => 'Theme archive']);

        return $this->processUpload($zipPath, $actor, $upload);
    }

    public function uploads(): array
    {
        return array_map(function ($record) {
            unset($record['path']);

            return $record;
        }, $this->store->query('theme_uploads', [], 100));
    }

    public function retry(string $id, string $actor): array
    {
        $upload = $this->store->get('theme_uploads', $id);
        abort_unless($upload && $upload['status'] === 'quarantined', 404);
        $temporary = tempnam(sys_get_temp_dir(), 'theme-scan-');
        try {
            file_put_contents($temporary, $this->files->read($upload['path']));
            chmod($temporary, 0600);

            return $this->processUpload($temporary, $actor, $upload);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function processUpload(string $zipPath, string $actor, array $upload): array
    {
        try {
            $theme = $this->process($zipPath, $actor);
        } catch (ValidationException $error) {
            $this->store->put('theme_uploads', $upload['id'], array_replace($upload, ['status' => 'rejected', 'message' => $error->getMessage()]));
            throw $error;
        } catch (ThemeScanUnavailable $error) {
            $this->store->put('theme_uploads', $upload['id'], array_replace($upload, ['message' => 'The archive is quarantined until the upload scanner is available.']));
            unset($upload['path']);

            return $upload + ['message' => 'The archive is quarantined until the upload scanner is available.'];
        }
        $completed = $upload;
        unset($completed['path']);
        $this->store->put('theme_uploads', $upload['id'], array_replace($completed, ['status' => 'accepted', 'theme_id' => $theme['id']]));
        $this->files->delete($upload['path']);

        return $theme;
    }

    private function process(string $zipPath, string $actor): array
    {
        if (! is_file($zipPath) || filesize($zipPath) > self::MAX_ZIP) {
            $this->invalid('Theme ZIP must be 25 MB or smaller.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            $this->invalid('Invalid ZIP archive.');
        }
        $paths = [];
        $contents = [];
        $assetEntries = [];
        $expanded = 0;
        $written = [];
        try {
            if ($zip->numFiles > 1000) {
                $this->invalid('A theme may contain at most 1,000 entries.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (! preg_match('~^[a-zA-Z0-9][a-zA-Z0-9_./-]*$~', $name) || str_contains($name, '..') || str_contains($name, '//') || strlen($name) > 200) {
                    $this->invalid('Unsafe archive path.');
                }
                $normalized = strtolower(rtrim($name, '/'));
                if (isset($paths[$normalized])) {
                    $this->invalid('Duplicate archive path.');
                }
                $paths[$normalized] = true;
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                if (($attributes >> 16 & 0170000) === 0120000) {
                    $this->invalid('Symbolic links are not allowed.');
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    $this->invalid('Encrypted archives are not allowed.');
                }
                if (str_ends_with($name, '/')) {
                    if (! preg_match('~^(assets|templates)(/[a-zA-Z0-9_-]+)*/$~', $name)) {
                        $this->invalid('Unsupported theme directory.');
                    }

                    continue;
                }
                $allowed = in_array($name, ['theme.json', 'tokens.json', 'preview.webp'], true) || preg_match('~^templates/[a-z0-9_-]+\.json$~', $name) || preg_match('~^assets/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+\.(png|jpe?g|webp|woff2)$~i', $name);
                if (! $allowed) {
                    $this->invalid('Unsupported file in theme: '.$name);
                }
                $expanded += (int) $stat['size'];
                if ($expanded > self::MAX_EXPANDED || (int) $stat['size'] > 20 * 1024 * 1024) {
                    $this->invalid('Expanded theme exceeds the size limit.');
                }
                if (str_starts_with($name, 'assets/') || $name === 'preview.webp') {
                    $assetEntries[$name] = $stat;

                    continue;
                }
                if ((int) $stat['size'] > 2_000_000) {
                    $this->invalid('Theme JSON files must be under 2 MB.');
                }
                $bytes = $zip->getFromIndex($i, (int) $stat['size'] + 1);
                if ($bytes === false || strlen($bytes) !== (int) $stat['size']) {
                    $this->invalid('Unreadable or oversized archive member.');
                }
                $contents[$name] = $bytes;
            }
            foreach (['theme.json', 'tokens.json'] as $required) {
                if (! isset($contents[$required])) {
                    $this->invalid('The archive must contain '.$required.'.');
                }
            }
            $manifest = $this->decode($contents['theme.json']);
            $allowedManifest = ['schema_version', 'name', 'version', 'renderer', 'author', 'license', 'templates', 'supported_blocks', 'navigation'];
            if (array_diff(array_keys($manifest), $allowedManifest)) {
                $this->invalid('Unsupported manifest properties.');
            }
            if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['renderer'] ?? null) !== 1) {
                $this->invalid('Unsupported Theme API or renderer version.');
            }
            foreach (['name', 'version', 'author', 'license'] as $field) {
                if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || mb_strlen($manifest[$field]) > 120) {
                    $this->invalid('Invalid theme manifest '.$field.'.');
                }
            }
            if (! preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/', $manifest['version'])) {
                $this->invalid('Theme version must follow semantic versioning.');
            }
            if (! is_array($manifest['supported_blocks'] ?? null) || array_diff($manifest['supported_blocks'], BlockDocument::TYPES)) {
                $this->invalid('Unsupported content blocks.');
            }
            $tokens = $this->validateTokens($this->decode($contents['tokens.json']));
            $templates = [];
            foreach ($contents as $name => $bytes) {
                if (str_starts_with($name, 'templates/')) {
                    $templates[pathinfo($name, PATHINFO_FILENAME)] = $this->decode($bytes);
                }
            }
            $templates = $this->validateTemplates($templates);
            if (isset($manifest['templates']) && (! is_array($manifest['templates']) || array_diff($manifest['templates'], array_keys($templates)))) {
                $this->invalid('Manifest references missing templates.');
            }
            $navigation = $this->validateNavigation($manifest['navigation'] ?? []);
            try {
                $clean = $this->scanner->scan(file_get_contents($zipPath), 'theme.zip');
            } catch (\Throwable $error) {
                throw new ThemeScanUnavailable('The scanner is unavailable.', previous: $error);
            }
            if (! $clean) {
                $this->invalid('The scanner rejected this archive.');
            }
            $assets = [];
            foreach ($assetEntries as $name => $stat) {
                $bytes = $zip->getFromName($name, (int) $stat['size'] + 1);
                if ($bytes === false || strlen($bytes) !== (int) $stat['size']) {
                    $this->invalid('Unreadable asset.');
                }
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($extension === 'woff2') {
                    if (substr($bytes, 0, 4) !== 'wOF2') {
                        $this->invalid('Invalid font file.');
                    }
                    $mime = 'font/woff2';
                } else {
                    $info = @getimagesizefromstring($bytes);
                    $mime = match ($extension) {
                        'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'
                    };
                    if (! $info || $info['mime'] !== $mime || $info[0] > 4096 || $info[1] > 4096) {
                        $this->invalid('Invalid or oversized image asset.');
                    }
                    $image = @imagecreatefromstring($bytes);
                    if (! $image) {
                        $this->invalid('Image asset cannot be decoded.');
                    }
                    ob_start();
                    match ($extension) {
                        'png' => imagepng($image), 'jpg', 'jpeg' => imagejpeg($image, null, 90), 'webp' => imagewebp($image, null, 90)
                    };
                    $bytes = ob_get_clean();
                    unset($image);
                }
                $path = $this->files->write($bytes, 'theme_assets');
                $written[] = $path;
                $assets[$name] = ['path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            }
            $manifest['theme_version'] = $manifest['version'];
            unset($manifest['version'], $manifest['templates'], $manifest['navigation']);
            $record = $this->store->transaction(function () use ($manifest, $tokens, $templates, $navigation, $assets, $actor) {
                $theme = $this->store->create('themes', $manifest + ['tokens' => $tokens, 'templates' => $templates, 'navigation' => $navigation, 'assets' => $assets, 'status' => 'validated', 'owner_id' => $actor, 'builtin' => false]);
                $this->audit->log($actor, 'themes.imported', 'themes', $theme['id']);

                return $theme;
            });

            return $this->response($record);
        } catch (\Throwable $error) {
            foreach ($written as $path) {
                $this->files->delete($path);
            } throw $error;
        } finally {
            $zip->close();
        }
    }

    public function asset(string $id, string $path): array
    {
        $theme = $this->get($id);
        $asset = $theme['assets'][$path] ?? null;
        abort_unless($asset !== null, 404);

        return ['contents' => $this->files->read($asset['path']), 'mime' => $asset['mime'], 'sha256' => $asset['sha256']];
    }

    public function response(array $theme): array
    {
        foreach ($theme['assets'] ?? [] as $name => $asset) {
            unset($asset['path']);
            $asset['url'] = '/theme-assets/'.$theme['id'].'/'.$name;
            $theme['assets'][$name] = $asset;
        }

        return $theme;
    }

    private function validateTokens(array $tokens): array
    {
        if (array_diff(array_keys($tokens), ['accent', 'ink', 'paper', 'muted', 'font_family', 'radius', 'content_width'])) {
            $this->invalid('Unsupported design token.');
        }
        $tokens = array_replace(['accent' => '#285448', 'ink' => '#202622', 'paper' => '#fbfaf6', 'muted' => '#6c726c', 'font_family' => 'serif', 'radius' => 4, 'content_width' => 1120], $tokens);
        foreach (['accent', 'ink', 'paper', 'muted'] as $key) {
            if (! is_string($tokens[$key]) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $tokens[$key])) {
                $this->invalid('Colors must be six-digit hex values.');
            }
        }
        if (! in_array($tokens['font_family'], ['serif', 'sans', 'system'], true)) {
            $this->invalid('Unsupported font family.');
        }
        foreach (['radius' => [0, 24], 'content_width' => [720, 1440]] as $key => [$min, $max]) {
            if (! is_int($tokens[$key]) || $tokens[$key] < $min || $tokens[$key] > $max) {
                $this->invalid('Invalid '.$key.'.');
            }
        }

        return $tokens;
    }

    private function validateTemplates(array $templates): array
    {
        if (empty($templates['page']) || count($templates) > 10) {
            $this->invalid('Provide a page template (at most ten templates).');
        }
        foreach ($templates as $name => $template) {
            if (! preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $name) || ! is_array($template) || array_diff(array_keys($template), ['sections']) || ! is_array($template['sections'] ?? null) || count($template['sections']) > 30) {
                $this->invalid('Invalid template.');
            }
            foreach ($template['sections'] as $section) {
                if (! is_array($section) || array_diff(array_keys($section), ['type', 'heading', 'text', 'button_label', 'button_url', 'columns', 'items']) || ! in_array($section['type'] ?? '', self::SECTIONS, true)) {
                    $this->invalid('Unsupported template section.');
                }
                foreach (['heading', 'text', 'button_label'] as $field) {
                    if (isset($section[$field]) && (! is_string($section[$field]) || mb_strlen($section[$field]) > 3000)) {
                        $this->invalid('Invalid section text.');
                    }
                }
                if (isset($section['button_url']) && ! $this->link($section['button_url'])) {
                    $this->invalid('Theme links must stay on this site.');
                }
                if (isset($section['columns']) && ! in_array($section['columns'], [1, 2, 3], true)) {
                    $this->invalid('Invalid column count.');
                }
                if (isset($section['items'])) {
                    if (! is_array($section['items']) || count($section['items']) > 12) {
                        $this->invalid('Invalid column items.');
                    }
                    foreach ($section['items'] as $item) {
                        if (! is_array($item) || array_diff(array_keys($item), ['heading', 'text', 'url'])) {
                            $this->invalid('Invalid column item.');
                        }
                        foreach (['heading', 'text'] as $field) {
                            if (isset($item[$field]) && (! is_string($item[$field]) || mb_strlen($item[$field]) > 1000)) {
                                $this->invalid('Invalid column content.');
                            }
                        }
                        if (isset($item['url']) && ! $this->link($item['url'])) {
                            $this->invalid('Unsafe column link.');
                        }
                    }
                }
            }
            if (count(array_filter($template['sections'], fn ($section) => $section['type'] === 'content')) !== 1) {
                $this->invalid('Every template must contain exactly one trusted content section.');
            }
        }

        return $templates;
    }

    private function validateNavigation(array $navigation): array
    {
        if (count($navigation) > 12) {
            $this->invalid('Navigation supports at most twelve links.');
        }
        foreach ($navigation as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['label', 'url']) || ! is_string($item['label'] ?? null) || mb_strlen($item['label']) > 80 || ! $this->link($item['url'] ?? '')) {
                $this->invalid('Invalid navigation item.');
            }
        }

        return $navigation;
    }

    private function link(mixed $url): bool
    {
        return is_string($url) && strlen($url) < 1000 && preg_match('~^/(?!/)[a-zA-Z0-9/_?=&#.%-]*$~', $url) && ! str_contains($url, '..');
    }

    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                $this->invalid('JSON files must contain objects.');
            }

            return $value;
        } catch (\JsonException) {
            $this->invalid('Invalid theme JSON.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['theme' => $message]);
    }
}
