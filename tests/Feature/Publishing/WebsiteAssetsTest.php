<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Publishing;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Publishing\Website;
use App\Domain\Publishing\WebsiteFonts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WebsiteAssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    private RecordStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/website-assets-'.bin2hex(random_bytes(8));
        config(['crm.private_path' => $this->storage, 'crm.require_mfa' => false, 'crm.store' => 'sql', 'crm.scanner.url' => null]);
        Route::middleware('web')->group(base_path('routes/website-assets.php'));
        $this->store = app(RecordStore::class);
        $this->signIn('owner');
    }

    protected function tearDown(): void
    {
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
        $user = $this->store->create('users', ['name' => ucfirst($role), 'email' => uniqid().'@example.com', 'roles' => [$role], 'status' => 'active', 'password' => 'unused']);
        $this->actingAs(new CrmUser($user))->withSession(['auth.confirmed_at' => time(), 'auth.mfa' => true]);
    }

    private function scanner(UploadedFile $image, string $verdict = 'clean'): void
    {
        config(['crm.scanner.url' => 'https://8.8.8.8/scan', 'crm.approved_hosts' => ['8.8.8.8']]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['sha256' => hash_file('sha256', $image->getRealPath()), 'verdict' => $verdict])]);
    }

    private function upload(): array
    {
        $image = UploadedFile::fake()->image('office.png', 30, 20);
        $this->scanner($image);

        return $this->postJson('/api/v1/website/media', ['image' => $image, 'alt' => 'Our office', 'rights' => 'Owned by the firm'])->assertCreated()->assertJsonPath('data.status', 'ready')->json('data');
    }

    private function publishImage(string $id, bool $visible = true): array
    {
        $state = app(Website::class)->state();
        $document = $state['draft'];
        $document['home']['sections'][0]['image_id'] = $id;
        $document['home']['sections'][0]['visible'] = $visible;

        return $this->store->put('settings', 'website-state', array_replace($state, ['published' => ['document' => $document]]));
    }

    public function test_scanned_raster_images_are_private_until_released_and_metadata_uses_versions(): void
    {
        $original = UploadedFile::fake()->image('photo.png', 30, 20);
        $image = UploadedFile::fake()->createWithContent('photo.php.png', file_get_contents($original->getRealPath()).'<?php echo "unsafe";');
        $this->scanner($image);
        $media = $this->postJson('/api/v1/website/media', ['image' => $image, 'alt' => 'Office'])->assertCreated()->assertJsonPath('data.status', 'ready')->json('data');
        $this->assertArrayNotHasKey('path', $media);
        $this->assertArrayNotHasKey('sha256', $media);
        $this->get($media['url'])->assertNotFound();
        $this->get($media['preview_url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertDontSee('<?php', false);
        $record = $this->store->get('website_media', $media['id']);
        $this->assertStringNotContainsString('<?php', file_get_contents($this->storage.'/'.$record['path']));
        $this->patchJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version'], 'alt' => 'Updated office', 'rights' => 'Firm photographer'])->assertOk()->assertJsonPath('data.alt', 'Updated office');
        $this->patchJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version'], 'alt' => 'Stale'])->assertConflict();
        $this->assertCount(0, $this->store->query('files'));
    }

    public function test_missing_scanner_and_digest_mismatch_keep_images_quarantined_then_retry_releases(): void
    {
        $image = UploadedFile::fake()->image('office.png', 30, 20);
        $media = $this->postJson('/api/v1/website/media', ['image' => $image])->assertCreated()->assertJsonPath('data.status', 'quarantined')->json('data');
        $this->assertNull($media['preview_url']);
        $this->get('/api/v1/website/media/'.$media['id'].'/preview')->assertNotFound();
        $this->publishImage($media['id']);
        $this->get('/website/media/'.$media['id'])->assertNotFound();
        config(['crm.scanner.url' => 'https://8.8.8.8/scan', 'crm.approved_hosts' => ['8.8.8.8']]);
        Http::fake(['*' => Http::response(['sha256' => str_repeat('0', 64), 'verdict' => 'clean'])]);
        $this->postJson('/api/v1/website/media/'.$media['id'].'/retry')->assertOk()->assertJsonPath('data.status', 'quarantined');
        $this->scanner($image);
        $this->postJson('/api/v1/website/media/'.$media['id'].'/retry')->assertOk()->assertJsonPath('data.status', 'ready');
        $this->get('/website/media/'.$media['id'])->assertOk();
    }

    public function test_rejected_uploads_cannot_be_retried_or_downloaded(): void
    {
        $image = UploadedFile::fake()->image('office.png', 30, 20);
        $this->scanner($image, 'infected');
        $media = $this->postJson('/api/v1/website/media', ['image' => $image])->assertCreated()->assertJsonPath('data.status', 'rejected')->json('data');
        $this->get('/api/v1/website/media/'.$media['id'].'/preview')->assertNotFound();
        $this->get('/website/media/'.$media['id'])->assertNotFound();
        $this->postJson('/api/v1/website/media/'.$media['id'].'/retry')->assertNotFound();
    }

    public function test_public_delivery_rechecks_live_references_and_hidden_sections_do_not_release_images(): void
    {
        $media = $this->upload();
        $this->publishImage($media['id'], false);
        $this->get($media['url'])->assertNotFound();
        $this->publishImage($media['id']);
        $response = $this->get($media['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $state = app(Website::class)->state();
        $this->store->put('settings', 'website-state', array_replace($state, ['published' => null]));
        $this->get($media['url'])->assertNotFound();
    }

    public function test_clients_cannot_manage_media_and_content_editors_can_upload(): void
    {
        $media = $this->upload();
        $this->signIn('client');
        $this->getJson('/api/v1/website/media')->assertForbidden();
        $this->getJson('/api/v1/website/fonts')->assertForbidden();
        $this->postJson('/api/v1/website/media', ['image' => UploadedFile::fake()->image('x.png')])->assertForbidden();
        $this->get($media['preview_url'])->assertForbidden();
        $this->patchJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version'], 'alt' => 'Changed'])->assertForbidden();
        $this->deleteJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version']])->assertForbidden();
        $this->signIn('content');
        $this->upload();
    }

    public function test_unsafe_file_types_bad_images_and_pixel_or_byte_limits_are_rejected(): void
    {
        Http::fake();
        foreach ([UploadedFile::fake()->createWithContent('bad.png', '<?php echo 1;'), UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>'), UploadedFile::fake()->image('wide.png', 8001, 1), UploadedFile::fake()->image('large.png', 3000, 3000), UploadedFile::fake()->createWithContent('huge.png', str_repeat('a', 10 * 1024 * 1024 + 1))] as $image) {
            $this->postJson('/api/v1/website/media', ['image' => $image])->assertUnprocessable();
        }
        $this->assertCount(0, $this->store->query('website_media'));
        Http::assertNothingSent();
    }

    public function test_deletion_preserves_draft_and_retained_revision_assets_and_removes_unused_files(): void
    {
        $media = $this->upload();
        $state = app(Website::class)->state();
        $state['draft']['organization']['logo_id'] = $media['id'];
        $this->store->put('settings', 'website-state', $state);
        $this->deleteJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version']])->assertUnprocessable();
        $revision = $this->store->create('website_revisions', ['document' => $state['draft']]);
        $state['draft']['organization']['logo_id'] = '';
        $state['history'] = [['id' => $revision['id']]];
        $this->store->put('settings', 'website-state', $state);
        $this->deleteJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version']])->assertUnprocessable();
        $state['history'] = [];
        $this->store->delete('website_revisions', $revision['id']);
        $this->store->put('settings', 'website-state', $state);
        $record = $this->store->get('website_media', $media['id']);
        $this->deleteJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version']])->assertOk();
        $this->assertFileDoesNotExist($this->storage.'/'.$record['path']);
        $this->get($media['preview_url'])->assertNotFound();
    }

    public function test_responsive_variants_preserve_aspect_ratio_and_share_publication_access_and_cleanup(): void
    {
        $image = UploadedFile::fake()->image('landscape.png', 3000, 1500);
        $this->scanner($image);
        $media = $this->postJson('/api/v1/website/media', ['image' => $image])->assertCreated()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.width', 1920)->assertJsonPath('data.height', 960)->assertJsonCount(2, 'data.sources')->json('data');
        $this->assertSame([768, 1280], array_column($media['sources'], 'width'));
        foreach ($media['sources'] as $source) {
            $this->assertArrayNotHasKey('path', $source);
            $this->get($source['url'])->assertNotFound();
            $preview = $this->get($source['preview_url'])->assertOk();
            $size = getimagesizefromstring($preview->getContent());
            $this->assertSame($source['width'], $size[0]);
            $this->assertSame($source['height'], $size[1]);
            $this->assertSame($source['width'] / 2, $size[1]);
        }
        $this->publishImage($media['id']);
        foreach ($media['sources'] as $source) {
            $response = $this->get($source['url'])->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->getJson($media['url'].'?width=9999')->assertUnprocessable();
        $this->signIn('client');
        $this->get($media['sources'][0]['preview_url'])->assertForbidden();
        $this->signIn('owner');
        $state = app(Website::class)->state();
        $this->store->put('settings', 'website-state', array_replace($state, ['published' => null]));
        foreach ($media['sources'] as $source) {
            $this->get($source['url'])->assertNotFound();
        }
        $record = $this->store->get('website_media', $media['id']);
        $paths = [$record['path'], ...array_column($record['variants'], 'path')];
        $this->deleteJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version']])->assertOk();
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($this->storage.'/'.$path);
        }
    }

    public function test_focal_points_are_bounded_and_small_images_do_not_upscale(): void
    {
        $media = $this->upload();
        $this->assertSame(30, $media['width']);
        $this->assertSame([], $media['sources']);
        $this->assertSame(50, $media['focal_x']);
        $this->get($media['preview_url'].'?width=768')->assertNotFound();
        foreach ([-1, 101, 3.5, 'invalid'] as $focal) {
            $this->patchJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version'], 'focal_x' => $focal])->assertUnprocessable();
        }
        $this->patchJson('/api/v1/website/media/'.$media['id'], ['expected_version' => $media['version'], 'focal_x' => 25, 'focal_y' => 75])->assertOk()->assertJsonPath('data.focal_x', 25)->assertJsonPath('data.focal_y', 75);
    }

    public function test_font_catalog_contains_bundled_verified_woff2_licenses_and_only_allowlisted_css(): void
    {
        Http::fake();
        $fonts = $this->getJson('/api/v1/website/fonts')->assertOk()->assertJsonCount(16, 'data')->json('data');
        foreach ($fonts as $font) {
            $this->assertStringContainsString('SIL OPEN FONT LICENSE', file_get_contents(public_path($font['license_url'])));
            foreach ($font['files'] as $file) {
                $bytes = file_get_contents(public_path($file['url']));
                $this->assertStringStartsWith('wOF2', $bytes);
                $this->assertSame($file['sha256'], hash('sha256', $bytes));
            }
        }
        $this->getJson('/api/v1/website/fonts?q=gurmukhi')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'noto-sans-gurmukhi');
        $css = app(WebsiteFonts::class)->css('lora', 'dm-sans');
        $this->assertStringContainsString('--heading-font:', $css);
        $this->assertStringContainsString('font-display:swap', $css);
        $this->assertStringNotContainsString('https://', $css);
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        app(WebsiteFonts::class)->css(['lora; background:url(https://evil.example)']);
    }
}
