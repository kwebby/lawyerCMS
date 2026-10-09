<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Publishing;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Publishing\Themes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class ThemeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $temporary = [];

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/theme-tests-'.bin2hex(random_bytes(8));
        config(['crm.private_path' => $this->storage, 'crm.require_mfa' => false, 'crm.store' => 'sql']);
        Route::middleware('web')->group(base_path('routes/publishing.php'));
        $user = app(RecordStore::class)->create('users', ['name' => 'Owner', 'email' => 'owner@example.com', 'roles' => ['owner'], 'status' => 'active', 'password' => 'unused']);
        $this->actingAs(new CrmUser($user))->withSession(['auth.confirmed_at' => time(), 'auth.mfa' => true]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            } rmdir($this->storage);
        } foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        } parent::tearDown();
    }

    private function zip(array $extras = [], ?callable $modify = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legal-theme-');
        $this->temporary[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('theme.json', json_encode(['schema_version' => 1, 'name' => 'Test theme', 'version' => '1.0.0', 'renderer' => 1, 'author' => 'Test', 'license' => 'MIT', 'supported_blocks' => ['paragraph'], 'templates' => ['page']]));
        $zip->addFromString('tokens.json', json_encode(['accent' => '#245544']));
        $zip->addFromString('templates/page.json', json_encode(['sections' => [['type' => 'hero'], ['type' => 'content']]]));
        foreach ($extras as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        if ($modify) {
            $modify($zip);
        }
        $zip->close();

        return $path;
    }

    public function test_valid_zip_is_immutable_activatable_and_can_be_rolled_back(): void
    {
        $path = $this->zip();
        config(['crm.scanner.url' => 'https://8.8.8.8/scan', 'crm.approved_hosts' => ['8.8.8.8']]);
        Http::fake(['*' => Http::response(['sha256' => hash_file('sha256', $path), 'verdict' => 'clean'])]);
        $theme = app(Themes::class)->import($path, auth()->id());
        $this->assertSame('1.0.0', $theme['theme_version']);
        $this->assertSame('validated', $theme['status']);
        $this->postJson('/api/v1/themes/'.$theme['id'].'/activate')->assertOk()->assertJsonPath('data.active', true);
        $this->assertSame($theme['id'], app(Themes::class)->active()['id']);
        $this->postJson('/api/v1/themes/rollback')->assertOk();
        $this->assertSame('builtin-chambers', app(Themes::class)->active()['id']);
        $this->get('/api/v1/themes/'.$theme['id'].'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_distributed_theme_examples_import_and_preview_with_the_documented_layout(): void
    {
        config(['crm.scanner.url' => 'https://8.8.8.8/scan', 'crm.approved_hosts' => ['8.8.8.8']]);
        foreach (['chambers', 'counsel', 'starter'] as $name) {
            $path = resource_path('themes/'.$name.'.zip');
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['sha256' => hash_file('sha256', $path), 'verdict' => 'clean'])]);
            $theme = app(Themes::class)->import($path, auth()->id());
            $this->assertSame('validated', $theme['status']);
            $this->assertSame('1.0.0', $theme['theme_version']);
            $this->assertArrayHasKey('page', $theme['templates']);
            if ($name === 'starter') {
                $this->assertSame('LawyerCMS Starter', $theme['name']);
                $this->assertArrayHasKey('article', $theme['templates']);
                $this->assertArrayHasKey('service', $theme['templates']);
                $archive = new ZipArchive;
                $archive->open($path);
                foreach (['theme.json', 'tokens.json', 'templates/page.json', 'templates/article.json', 'templates/service.json'] as $entry) {
                    $this->assertSame(file_get_contents(resource_path('themes/starter/'.$entry)), $archive->getFromName($entry));
                }
                $archive->close();
            }
            $this->get('/api/v1/themes/'.$theme['id'].'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_scanner_outage_keeps_theme_quarantined_and_retry_requires_a_clean_digest(): void
    {
        config(['crm.scanner.url' => null]);
        $path = $this->zip();
        $pending = app(Themes::class)->import($path, auth()->id());
        $this->assertSame('quarantined', $pending['status']);
        $this->assertArrayNotHasKey('path', $pending);
        $this->assertCount(0, app(RecordStore::class)->query('themes'));
        $this->postJson('/api/v1/themes/'.$pending['id'].'/activate')->assertNotFound();
        config(['crm.scanner.url' => 'https://8.8.8.8/scan', 'crm.approved_hosts' => ['8.8.8.8']]);
        Http::fake(['*' => Http::response(['sha256' => hash_file('sha256', $path), 'verdict' => 'clean'])]);
        $this->postJson('/api/v1/themes/uploads/'.$pending['id'].'/retry')->assertOk()->assertJsonPath('data.status', 'validated');
        $this->assertCount(1, app(RecordStore::class)->query('themes'));
    }

    public function test_zip_traversal_script_nested_archive_and_case_duplicates_are_rejected(): void
    {
        foreach ([['../escape.php' => '<?php bad();'], ['assets/main.js' => 'alert(1)'], ['assets/archive.zip' => 'PK'], ['assets/a.png' => 'x', 'assets/A.png' => 'y'], ["assets/a.png\n" => 'x']] as $files) {
            try {
                app(Themes::class)->import($this->zip($files), auth()->id());
                $this->fail('Unsafe archive was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('theme', $error->errors());
            }
        }
        $this->assertCount(0, app(RecordStore::class)->query('themes'));
    }

    public function test_zip_symlinks_and_encryption_are_rejected(): void
    {
        $symlink = $this->zip(['assets/link.png' => '/etc/passwd'], fn ($zip) => $zip->setExternalAttributesName('assets/link.png', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        try {
            app(Themes::class)->import($symlink, auth()->id());
            $this->fail('Symlink accepted.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Symbolic', $error->getMessage());
        }
        $encrypted = $this->zip([], function ($zip) {
            $zip->setPassword('test-password');
            $zip->setEncryptionName('tokens.json', ZipArchive::EM_AES_256);
        });
        try {
            app(Themes::class)->import($encrypted, auth()->id());
            $this->fail('Encrypted ZIP accepted.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Encrypted', $error->getMessage());
        }
    }

    public function test_archive_limits_and_executable_theme_sections_are_rejected(): void
    {
        $tooMany = $this->zip([], function ($zip) {
            for ($i = 0; $i < 1000; $i++) {
                $zip->addEmptyDir('assets/f'.$i);
            }
        });
        try {
            app(Themes::class)->import($tooMany, auth()->id());
            $this->fail('Entry count ignored.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('1,000', $error->getMessage());
        }
        $large = $this->zip(['assets/large.png' => str_repeat('a', 21 * 1024 * 1024)]);
        try {
            app(Themes::class)->import($large, auth()->id());
            $this->fail('Size limit ignored.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('size limit', $error->getMessage());
        }
        $this->postJson('/api/v1/themes/designer', ['name' => 'Unsafe', 'tokens' => ['accent' => 'red; background:url(https://example.com)'], 'templates' => ['page' => ['sections' => [['type' => 'content']]]]])->assertUnprocessable();
        $this->postJson('/api/v1/themes/designer', ['name' => 'Unsafe', 'tokens' => [], 'templates' => ['page' => ['sections' => [['type' => 'script'], ['type' => 'content']]]]])->assertUnprocessable();
        try {
            app(Themes::class)->design(['name' => 'Unsafe', 'tokens' => ['accent' => "#245544\n"], 'templates' => ['page' => ['sections' => [['type' => 'content']]]]], auth()->id());
            $this->fail('A color with a trailing newline was accepted.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('six-digit', $error->getMessage());
        }
    }
}
