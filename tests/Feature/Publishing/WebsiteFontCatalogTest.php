<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Publishing;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Publishing\WebsiteFonts;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteFontCatalogTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private string $storage;

    private string $secret = 'google-font-secret-key-for-testing';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/website-fonts-'.bin2hex(random_bytes(8));
        config(['crm.private_path' => $this->storage.'/private', 'crm.require_mfa' => false, 'crm.store' => 'sql', 'cache.stores.file.path' => $this->storage.'/cache', 'cache.stores.file.lock_path' => $this->storage.'/locks']);
        Cache::purge('file');
        Http::swap(new Factory);
        $this->store = app(RecordStore::class);
        $this->signIn('owner');
    }

    protected function tearDown(): void
    {
        Cache::purge('file');
        if (is_dir($this->storage)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    private function signIn(string $role): void
    {
        $record = $this->store->create('users', ['name' => ucfirst($role), 'email' => uniqid().'@example.com', 'roles' => [$role], 'status' => 'active', 'password' => 'unused']);
        $this->actingAs(new CrmUser($record))->withSession(['auth.confirmed_at' => time(), 'auth.mfa' => true]);
    }

    private function configure(): void
    {
        $this->patchJson('/api/v1/website/fonts/settings', ['api_key' => $this->secret])->assertOk()->assertJsonPath('data.api_key_configured', true)->assertDontSee($this->secret);
    }

    private function css(): string
    {
        return "/* latin */\n@font-face {font-family: 'Abel';font-style: normal;font-weight: 400;font-display: swap;src: url(https://fonts.gstatic.com/s/abel/v1/abel.woff2) format('woff2');unicode-range: U+0000-00FF;}";
    }

    private function fakeProvider(?string $css = null, ?string $font = null, ?callable $override = null): void
    {
        Http::swap(new Factory);
        $bundled = app(WebsiteFonts::class)->find('lora')['files'][0]['url'];
        $bytes = $font ?? file_get_contents(public_path($bundled));
        Http::fake(function (Request $request) use ($css, $bytes, $override) {
            if ($override && ($response = $override($request)) !== null) {
                return $response;
            }
            $url = $request->url();
            if (str_starts_with($url, 'https://www.googleapis.com/webfonts/v1/webfonts')) {
                return Http::response(['items' => [['family' => 'Abel', 'category' => 'sans-serif', 'variants' => ['regular'], 'subsets' => ['latin']], ['family' => 'Lora', 'category' => 'serif', 'variants' => ['regular', '600', '700'], 'subsets' => ['latin', 'latin-ext']]]]);
            }
            if ($url === 'https://raw.githubusercontent.com/google/fonts/main/ofl/abel/METADATA.pb') {
                return Http::response("name: \"Abel\"\nlicense: \"OFL\"\n");
            }
            if ($url === 'https://raw.githubusercontent.com/google/fonts/main/ofl/abel/OFL.txt') {
                return Http::response(file_get_contents(public_path('fonts/website/lora/OFL.txt')));
            }
            if (str_starts_with($url, 'https://fonts.googleapis.com/css2')) {
                return Http::response($css ?? $this->css());
            }
            if ($url === 'https://fonts.gstatic.com/s/abel/v1/abel.woff2') {
                return Http::response($bytes);
            }

            return Http::response('Unexpected request', 500);
        });
    }

    public function test_offline_library_works_without_optional_api_key_and_configuration_never_exposes_it(): void
    {
        Http::fake();
        $this->getJson('/api/v1/website/fonts')->assertOk()->assertJsonCount(16, 'data');
        $this->getJson('/api/v1/website/fonts/settings')->assertOk()->assertExactJson(['data' => ['api_key_configured' => false]]);
        $this->getJson('/api/v1/website/fonts/catalog')->assertStatus(503)->assertSee('Google Fonts Developer API key');
        $this->configure();
        $this->getJson('/api/v1/website/fonts/settings')->assertOk()->assertExactJson(['data' => ['api_key_configured' => true]]);
        $stored = $this->store->get('settings', 'website_fonts');
        $this->assertNotSame($this->secret, $stored['api_key']);
        $this->assertSame($this->secret, app(Settings::class)->get('website_fonts', true)['api_key']);
        $this->assertStringNotContainsString($this->secret, json_encode($this->store->query('audit')));
        Http::assertNothingSent();
    }

    public function test_full_catalog_is_cached_filtered_and_does_not_expose_provider_keys(): void
    {
        $this->configure();
        $this->fakeProvider();
        $this->getJson('/api/v1/website/fonts/catalog?q=Abel')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.family', 'Abel')->assertJsonPath('data.0.installed', false)->assertDontSee($this->secret);
        $this->getJson('/api/v1/website/fonts/catalog?q=Lora')->assertOk()->assertJsonPath('data.0.installed', true)->assertJsonPath('data.0.id', 'lora');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/webfonts/v1/webfonts') && $request['key'] === $this->secret);
    }

    public function test_verified_install_becomes_a_local_font_with_preserved_license_and_is_idempotent(): void
    {
        $this->configure();
        $this->fakeProvider();
        $font = $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertCreated()->assertJsonPath('data.id', 'google-abel')->assertJsonPath('data.weights', [400])->assertJsonPath('data.self_hosted', true)->json('data');
        $this->getJson('/api/v1/website/fonts')->assertOk()->assertJsonCount(17, 'data');
        $this->assertStringContainsString("'Abel'", app(WebsiteFonts::class)->css('google-abel', 'lora'));
        $this->assertStringNotContainsString('https://', app(WebsiteFonts::class)->css(['google-abel']));
        $record = $this->store->get('website_fonts', 'google-abel');
        $this->assertSame('ready', $record['status']);
        $this->assertArrayNotHasKey('assets', $font);
        $this->assertArrayNotHasKey('path', $font['files'][0]);
        $response = $this->get($font['files'][0]['url'])->assertOk()->assertHeader('Content-Type', 'font/woff2');
        $this->assertStringStartsWith('wOF2', $response->getContent());
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->get($font['license_url'])->assertOk()->assertSee('SIL OPEN FONT LICENSE');
        foreach ($record['assets'] as $asset) {
            $this->assertStringNotContainsString('wOF2', file_get_contents($this->storage.'/private/'.$asset['path']));
        }
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://fonts.googleapis.com/css2') && $request['family'] === 'Abel:wght@400');
        $sent = count(Http::recorded());
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertCreated()->assertJsonPath('data.id', 'google-abel');
        Http::assertSentCount($sent);
        $record['status'] = 'quarantined';
        $this->store->put('website_fonts', $record['id'], $record);
        $this->get($font['files'][0]['url'])->assertNotFound();
    }

    public function test_role_and_fresh_authentication_controls_protect_font_installation_and_credentials(): void
    {
        $this->configure();
        $this->fakeProvider();
        $this->signIn('content');
        $this->getJson('/api/v1/website/fonts/catalog')->assertOk();
        $this->getJson('/api/v1/website/fonts/settings')->assertForbidden();
        $this->patchJson('/api/v1/website/fonts/settings', ['api_key' => 'replacement-key'])->assertForbidden();
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertForbidden();
        $this->signIn('client');
        $this->getJson('/api/v1/website/fonts/catalog')->assertForbidden();
        $this->signIn('owner');
        $this->withSession(['auth.confirmed_at' => time() - 7200])->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertStatus(423);
    }

    public function test_css_code_and_non_official_download_destinations_are_rejected_without_installing(): void
    {
        $this->configure();
        foreach ([str_replace('fonts.gstatic.com', 'evil.example', $this->css()), str_replace('https://fonts.gstatic.com/s/abel/v1/abel.woff2', 'http://127.0.0.1/private', $this->css()), $this->css().' body{background:url(https://evil.example)}', str_replace('font-display: swap;', 'font-display: swap;background:url(https://evil.example);', $this->css())] as $css) {
            $this->fakeProvider($css);
            $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertUnprocessable();
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example') || str_contains($request->url(), '127.0.0.1'));
        }
        $this->assertCount(0, $this->store->query('website_fonts'));
    }

    public function test_redirects_upstream_failures_and_credentials_in_provider_errors_are_not_forwarded(): void
    {
        $this->configure();
        $this->fakeProvider(override: fn (Request $request) => str_contains($request->url(), 'googleapis.com/webfonts') ? Http::response('Rejected key '.$this->secret, 403) : null);
        $this->getJson('/api/v1/website/fonts/catalog')->assertStatus(503)->assertDontSee($this->secret);
        $this->fakeProvider(override: fn (Request $request) => str_contains($request->url(), 'METADATA.pb') ? Http::response('', 302, ['Location' => 'http://127.0.0.1/secret']) : null);
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertStatus(503)->assertDontSee('127.0.0.1');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1'));
        $this->assertCount(0, $this->store->query('website_fonts'));
    }

    public function test_license_identity_limits_and_invalid_binaries_fail_closed(): void
    {
        $this->configure();
        $this->fakeProvider(override: fn (Request $request) => str_contains($request->url(), 'METADATA.pb') ? Http::response('name: "Different Family"') : null);
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertUnprocessable();
        $this->fakeProvider(str_repeat($this->css(), 49));
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertUnprocessable();
        $this->fakeProvider(font: 'not-a-font');
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertUnprocessable();
        $this->fakeProvider(font: str_repeat('x', 2 * 1024 * 1024 + 1));
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertStatus(503);
        $this->assertCount(0, $this->store->query('website_fonts'));
    }

    public function test_unknown_families_and_conflicting_non_ready_records_cannot_appear_installed(): void
    {
        $this->configure();
        $this->fakeProvider();
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Unknown Family'])->assertUnprocessable();
        $this->store->create('website_fonts', ['status' => 'quarantined', 'font' => ['name' => 'Abel']], 'google-abel');
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertUnprocessable();
        $this->assertSame('quarantined', $this->store->get('website_fonts', 'google-abel')['status']);
        $this->getJson('/api/v1/website/fonts')->assertJsonCount(16, 'data');
        $this->assertSame([], glob($this->storage.'/private/website_fonts/*.enc'));
    }

    public function test_malformed_variant_fields_are_sanitized_without_exposing_an_upstream_error(): void
    {
        $this->configure();
        $this->fakeProvider(override: fn (Request $request) => str_contains($request->url(), 'googleapis.com/webfonts') ? Http::response(['items' => [['family' => 'Abel', 'category' => 'sans-serif', 'variants' => 'malformed', 'subsets' => 123]]]) : null);
        $this->getJson('/api/v1/website/fonts/catalog')->assertOk()->assertJsonPath('data.0.variants', [])->assertJsonPath('data.0.subsets', []);
        $this->postJson('/api/v1/website/fonts/install', ['family' => 'Abel'])->assertUnprocessable();
    }
}
