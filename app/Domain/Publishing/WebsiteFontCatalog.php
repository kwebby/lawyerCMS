<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\Audit;
use App\Support\PrivateFiles;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;

final class WebsiteFontCatalog
{
    private const CACHE_KEY = 'website-google-fonts-catalog-v1';

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    public function __construct(private RecordStore $store, private Settings $settings, private PrivateFiles $files, private Audit $audit, private WebsiteFonts $fonts) {}

    public function configuration(): array
    {
        return ['api_key_configured' => (bool) ($this->settings->get('website_fonts')['api_key_configured'] ?? false)];
    }

    public function configure(string $key, string $actor): array
    {
        $this->settings->save('website_fonts', ['api_key' => $key]);
        Cache::store('file')->forget(self::CACHE_KEY);
        $this->audit->log($actor, 'website.font_catalog.configured', 'settings', 'website_fonts');

        return $this->configuration();
    }

    public function catalog(?string $query = null, ?float $deadline = null): array
    {
        $key = $this->settings->get('website_fonts', true)['api_key'] ?? null;
        abort_unless(is_string($key) && $key !== '', 503, 'An administrator must configure a Google Fonts Developer API key to browse the full catalog. The installed font library remains available.');
        $catalog = Cache::store('file')->get(self::CACHE_KEY);
        if (! is_array($catalog)) {
            $json = $this->fetch('https://www.googleapis.com/webfonts/v1/webfonts', 12 * 1024 * 1024, ['key' => $key], min($deadline ?? (microtime(true) + 12), microtime(true) + 12));
            try {
                $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                abort(503, 'The Google Fonts catalog returned an invalid response. Try again later.');
            }
            abort_unless(is_array($result['items'] ?? null) && count($result['items']) <= 10000, 503, 'The Google Fonts catalog response is unavailable.');
            $catalog = [];
            foreach ($result['items'] as $item) {
                if (! is_array($item) || ! is_string($item['family'] ?? null) || mb_strlen($item['family']) > 100) {
                    continue;
                }
                $catalog[] = ['family' => $item['family'], 'category' => in_array($item['category'] ?? '', ['serif', 'sans-serif', 'display', 'handwriting', 'monospace'], true) ? $item['category'] : 'sans-serif', 'variants' => array_values(array_filter(is_array($item['variants'] ?? null) ? $item['variants'] : [], fn ($value): bool => is_string($value) && preg_match('/^(?:regular|italic|[1-9]00(?:italic)?)$/D', $value))), 'subsets' => array_values(array_filter(is_array($item['subsets'] ?? null) ? $item['subsets'] : [], fn ($value): bool => is_string($value) && preg_match('/^[a-z0-9-]{1,50}$/D', $value)))];
            }
            Cache::store('file')->put(self::CACHE_KEY, $catalog, now()->addHours(12));
        }
        $installed = array_column($this->fonts->catalog(), 'id', 'name');
        $query = mb_strtolower(trim($query ?? ''));
        $catalog = array_values(array_filter($catalog, fn (array $font): bool => $query === '' || str_contains(mb_strtolower($font['family'].' '.$font['category'].' '.implode(' ', $font['subsets'])), $query)));

        return array_map(fn (array $font): array => $font + ['installed' => isset($installed[$font['family']]), 'id' => $installed[$font['family']] ?? null], $catalog);
    }

    public function install(string $family, string $actor): array
    {
        foreach ($this->fonts->catalog() as $font) {
            if ($font['name'] === $family) {
                return $font;
            }
        }
        $deadline = microtime(true) + 25;
        $entry = collect($this->catalog(null, $deadline))->first(fn (array $font): bool => $font['family'] === $family);
        if (! $entry || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{0,99}$/D', $family)) {
            $this->invalid('Choose a supported family from the Google Fonts catalog.');
        }
        if (count($this->store->query('website_fonts', ['status' => 'ready'], 101)) >= 100) {
            $this->invalid('The installation limit is 100 additional font families.');
        }
        $weights = array_values(array_filter([400, 600, 700], fn (int $weight): bool => in_array($weight === 400 ? 'regular' : (string) $weight, $entry['variants'], true) || in_array((string) $weight, $entry['variants'], true)));
        if ($weights === []) {
            $this->invalid('This family does not provide a supported upright 400, 600, or 700 weight.');
        }
        $license = $this->license($family, $deadline);
        $css = $this->fetch('https://fonts.googleapis.com/css2', 512 * 1024, ['family' => $family.':wght@'.implode(';', $weights), 'display' => 'swap'], $deadline);
        $faces = $this->parseCss($css, $family, $weights);
        $id = 'google-'.Str::slug($family);
        $assets = [];
        $paths = [];
        $total = 0;
        $fontFiles = [];
        try {
            foreach ($faces as $index => $face) {
                $bytes = $this->fetch($face['source_url'], 2 * 1024 * 1024, [], $deadline);
                $total += strlen($bytes);
                if ($total > 12 * 1024 * 1024 || ! $this->validWoff2($bytes)) {
                    $this->invalid('The font files are invalid or exceed the 12 MB family limit.');
                }
                $sha = hash('sha256', $bytes);
                $file = 'face-'.$index.'-'.substr($sha, 0, 16).'.woff2';
                $path = $this->files->write($bytes, 'website_fonts');
                $paths[] = $path;
                $assets[$file] = ['path' => $path, 'mime' => 'font/woff2'];
                $fontFiles[] = $face + ['url' => '/website/fonts/'.$id.'/'.$file, 'bytes' => strlen($bytes), 'sha256' => $sha];
            }
            $licensePath = $this->files->write($license['contents'], 'website_fonts');
            $paths[] = $licensePath;
            $assets[$license['file']] = ['path' => $licensePath, 'mime' => 'text/plain; charset=UTF-8'];
            $category = $entry['category'];
            $fallback = in_array($category, ['serif', 'sans-serif', 'monospace'], true) ? $category : 'sans-serif';
            $font = ['id' => $id, 'name' => $family, 'category' => $category, 'css_family' => "'".$family."', ".$fallback, 'weights' => $weights, 'styles' => ['normal'], 'languages' => $entry['subsets'], 'files' => $fontFiles, 'license' => $license['type'], 'license_url' => '/website/fonts/'.$id.'/'.$license['file'], 'license_source_url' => $license['source_url'], 'source_url' => 'https://fonts.google.com/specimen/'.rawurlencode($family), 'self_hosted' => true];
            $saved = $this->store->transaction(function () use ($id, $font, $assets, $actor): array {
                $existing = $this->store->get('website_fonts', $id);
                if ($existing) {
                    if (($existing['status'] ?? '') !== 'ready' || ($existing['font']['name'] ?? '') !== $font['name']) {
                        $this->invalid('A conflicting font installation record exists. An administrator must review it before retrying.');
                    }

                    return ['font' => $existing['font'], 'created' => false];
                }
                $this->store->create('website_fonts', ['status' => 'ready', 'font' => $font, 'assets' => $assets, 'created_by' => $actor], $id);
                $this->audit->log($actor, 'website.font.installed', 'website_fonts', $id, ['family' => $font['name']]);

                return ['font' => $font, 'created' => true];
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                $this->files->delete($path);
            }
            throw $error;
        }
        if (! $saved['created']) {
            foreach ($paths as $path) {
                $this->files->delete($path);
            }
        }

        return $saved['font'];
    }

    public function asset(string $id, string $file): array
    {
        $record = $this->store->get('website_fonts', $id);
        abort_unless($record && $record['status'] === 'ready' && isset($record['assets'][$file]), 404);
        $asset = $record['assets'][$file];

        return ['contents' => $this->files->read($asset['path']), 'mime' => $asset['mime']];
    }

    private function license(string $family, float $deadline): array
    {
        $folder = strtolower(str_replace([' ', '-'], '', $family));
        foreach ([['directory' => 'ofl', 'file' => 'OFL.txt', 'type' => 'OFL-1.1', 'marker' => 'SIL OPEN FONT LICENSE'], ['directory' => 'apache', 'file' => 'LICENSE.txt', 'type' => 'Apache-2.0', 'marker' => 'Apache License']] as $candidate) {
            $base = 'https://raw.githubusercontent.com/google/fonts/main/'.$candidate['directory'].'/'.$folder.'/';
            $metadata = $this->fetch($base.'METADATA.pb', 128 * 1024, [], $deadline, true);
            if ($metadata === '') {
                continue;
            }
            if (! preg_match('/^name:\s*"'.preg_quote($family, '/').'"\s*$/m', $metadata)) {
                $this->invalid('The upstream font identity could not be verified.');
            }
            $contents = $this->fetch($base.$candidate['file'], 128 * 1024, [], $deadline);
            if (! str_contains($contents, $candidate['marker'])) {
                $this->invalid('The upstream font license could not be verified.');
            }

            return $candidate + ['contents' => $contents, 'source_url' => $base.$candidate['file']];
        }
        $this->invalid('An authoritative redistribution license could not be verified for this family.');
    }

    private function parseCss(string $css, string $family, array $weights): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/@font-face\s*\{([^{}]+)\}/s', $css, $matches);
        $remaining = preg_replace('/@font-face\s*\{([^{}]+)\}/s', '', $css);
        if (trim($remaining) !== '' || count($matches[1]) < 1 || count($matches[1]) > 48) {
            $this->invalid('The font stylesheet is unsupported or exceeds the 48-file limit.');
        }
        $faces = [];
        foreach ($matches[1] as $block) {
            $properties = [];
            foreach (explode(';', trim($block)) as $declaration) {
                if (trim($declaration) === '') {
                    continue;
                }
                $parts = explode(':', $declaration, 2);
                if (count($parts) !== 2 || isset($properties[trim($parts[0])])) {
                    $this->invalid('The font stylesheet contains an invalid declaration.');
                }
                $properties[trim($parts[0])] = trim($parts[1]);
            }
            if (array_diff(array_keys($properties), ['font-family', 'font-style', 'font-weight', 'font-display', 'font-stretch', 'src', 'unicode-range']) || trim($properties['font-family'] ?? '', "'\"") !== $family || ($properties['font-style'] ?? '') !== 'normal' || ! in_array($properties['font-weight'] ?? '', array_map(strval(...), $weights), true)) {
                $this->invalid('The font stylesheet includes unsupported font declarations.');
            }
            if (! preg_match("#^url\\((https://fonts\\.gstatic\\.com/s/[A-Za-z0-9/_-]+\\.woff2)\\)\\s+format\\(['\"]woff2['\"]\\)$#D", $properties['src'] ?? '', $source)) {
                $this->invalid('Only official HTTPS Google Fonts WOFF2 files can be installed.');
            }
            $range = $properties['unicode-range'] ?? 'U+0000-10FFFF';
            if (strlen($range) > 16000 || ! preg_match('/^U\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?(?:,\s*U\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?)*$/D', $range)) {
                $this->invalid('The font stylesheet contains an invalid Unicode range.');
            }
            $faces[] = ['source_url' => $source[1], 'weight' => $properties['font-weight'], 'style' => 'normal', 'unicode_range' => $range, 'subset' => 'subset-'.count($faces)];
        }

        return $faces;
    }

    private function validWoff2(string $bytes): bool
    {
        if (strlen($bytes) < 48 || substr($bytes, 0, 4) !== 'wOF2') {
            return false;
        }
        $header = unpack('Nlength/ntables/nreserved/Nsfnt_size', substr($bytes, 8, 12));

        return $header['length'] === strlen($bytes) && $header['tables'] > 0 && $header['tables'] <= 128 && $header['reserved'] === 0 && $header['sfnt_size'] <= 20 * 1024 * 1024;
    }

    /** Every remote URL is generated from fixed official endpoints or a strictly parsed gstatic WOFF2 URL. */
    private function fetch(string $url, int $limit, array $query, float $deadline, bool $optional = false): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || ! in_array($parts['host'] ?? '', ['www.googleapis.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'raw.githubusercontent.com'], true)) {
            $this->invalid('The font download destination is not permitted.');
        }
        $remaining = $deadline - microtime(true);
        abort_if($remaining <= 0, 503, 'Font installation exceeded the time budget. Try again later.');
        try {
            $response = Http::withUserAgent(self::USER_AGENT)->timeout(min(10, $remaining))->connectTimeout(min(4, $remaining))->withOptions(['allow_redirects' => false, 'on_headers' => function (ResponseInterface $response) use ($limit): void {
                if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                    throw new \RuntimeException('Response limit exceeded.');
                }
            }, 'progress' => function ($downloadTotal, $downloaded) use ($limit): void {
                if ($downloadTotal > $limit || $downloaded > $limit) {
                    throw new \RuntimeException('Response limit exceeded.');
                }
            }])->get($url, $query);
        } catch (\Throwable) {
            abort(503, 'The Google Fonts service could not be reached within the download limits. Try again later.');
        }
        if ($optional && $response->status() === 404) {
            return '';
        }
        abort_unless($response->successful() && strlen($response->body()) <= $limit, 503, 'The Google Fonts service returned an unavailable or oversized response. Check the API key and try again.');

        return $response->body();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['family' => $message]);
    }
}
