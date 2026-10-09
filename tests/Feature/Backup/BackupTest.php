<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Backup;

use App\Contracts\RecordStore;
use App\Support\Backup;
use App\Support\PrivateFiles;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private RecordStore $store;

    private bool $maintenance = true;

    private const PASSWORD = 'a-long-separate-recovery-passphrase';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/legal-backup-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['crm.private_path' => $this->directory.'/source-vault', 'crm.store' => 'sql']);
        $mode = \Mockery::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturnUsing(fn () => $this->maintenance);
        $this->app->instance(MaintenanceMode::class, $mode);
        $this->store = app(RecordStore::class);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        } rmdir($this->directory);
        parent::tearDown();
    }

    public function test_streaming_snapshot_roundtrips_ids_versions_files_and_excludes_public_analyzer_material(): void
    {
        $file = app(PrivateFiles::class)->write('Private confidential document content', 'documents');
        $record = $this->store->create('documents', ['title' => 'A confidential letter', 'path' => $file, 'status' => 'clean']);
        $record = $this->store->put('documents', $record['id'], array_replace($record, ['title' => 'Updated confidential letter']), 1);
        for ($i = 0; $i < 503; $i++) {
            $this->store->create('contacts', ['name' => 'Contact '.$i, 'email' => 'private-'.$i.'@example.com']);
        }
        $temporary = app(PrivateFiles::class)->write('Expiring public notice', 'temporary');
        $this->store->create('analyzer_uploads', ['path' => $temporary]);
        $this->store->create('ai_runs', ['context' => 'public', 'result_path' => $temporary]);
        $this->store->create('jobs', ['type' => 'analyzer.run', 'payload' => ['run_id' => 'temporary']]);
        $this->store->create('identity_tokens', ['kind' => 'reset', 'token' => 'sensitive-reset-token']);
        app(PrivateFiles::class)->write('Unreferenced old file', 'documents');
        $archive = $this->directory.'/firm.lcrm';
        $result = app(Backup::class)->create($archive, self::PASSWORD);
        $this->assertSame(504, $result['records']);
        $this->assertSame(1, $result['files']);
        $this->assertSame(4, $result['excluded_records']);
        $this->assertStringNotContainsString('confidential', file_get_contents($archive));
        $this->assertSame(504, app(Backup::class)->inspect($archive, self::PASSWORD)['records']);
        DB::table('crm_records')->delete();
        config(['crm.private_path' => $this->directory.'/restored-vault']);
        $restored = app(Backup::class)->restore($archive, self::PASSWORD);
        $this->assertTrue($restored['restored']);
        $restoredRecord = $this->store->get('documents', $record['id']);
        ksort($record);
        ksort($restoredRecord);
        $this->assertSame($record, $restoredRecord);
        $this->assertSame('Private confidential document content', app(PrivateFiles::class)->read($file));
        $this->assertSame(504, DB::table('crm_records')->count());
        $this->assertCount(0, $this->store->query('analyzer_uploads'));
        $this->assertDirectoryDoesNotExist($this->directory.'/restored-vault/temporary');
    }

    public function test_untrusted_text_shaped_like_a_vault_path_never_blocks_a_backup(): void
    {
        $lookalike = 'documents/00000000-0000-0000-0000-000000000000.enc';
        $temporary = 'temporary/00000000-0000-0000-0000-000000000000.enc';
        $lead = $this->store->create('leads', ['name' => $lookalike, 'jurisdiction' => $temporary, 'email' => 'visitor@example.test', 'source' => 'website', 'status' => 'new']);
        $message = $this->store->create('messages', ['conversation_id' => 'c1', 'body' => $temporary, 'attachment_ids' => []]);
        $this->store->create('message_revisions', ['message_id' => $message['id'], 'body' => $lookalike]);
        $this->store->create('themes', ['name' => 'Imported', 'navigation' => [['label' => 'x', 'path' => $temporary]], 'tokens' => ['font' => $lookalike], 'assets' => []]);
        $file = app(PrivateFiles::class)->write('Retained letter', 'quarantine');
        $this->store->create('documents', ['title' => $lookalike, 'name' => $temporary, 'path' => $file, 'status' => 'clean']);
        $archive = $this->directory.'/firm.lcrm';
        $result = app(Backup::class)->create($archive, self::PASSWORD);
        $this->assertSame(5, $result['records']);
        $this->assertSame(1, $result['files']);
        DB::table('crm_records')->delete();
        config(['crm.private_path' => $this->directory.'/restored-vault']);
        app(Backup::class)->restore($archive, self::PASSWORD);
        $this->assertSame($lookalike, $this->store->get('leads', $lead['id'])['name']);
        $this->assertSame('Retained letter', app(PrivateFiles::class)->read($file));
    }

    public function test_application_file_fields_are_copied_and_a_missing_or_temporary_reference_still_aborts(): void
    {
        $files = app(PrivateFiles::class);
        $page = $files->write('[]', 'content');
        $published = $files->write('[{"type":"paragraph"}]', 'content');
        $revision = $files->write('[]', 'content');
        $asset = $files->write('theme image', 'theme_assets');
        $variant = $files->write('resized image', 'website_media');
        $this->store->create('pages', ['title' => 'Home', 'blocks_path' => $page, 'published_snapshot' => ['blocks_path' => $published]]);
        $this->store->create('content_revisions', ['collection' => 'pages', 'snapshot' => ['blocks_path' => $revision]]);
        $this->store->create('themes', ['name' => 'Imported', 'assets' => ['hero.png' => ['path' => $asset, 'mime' => 'image/png']]]);
        $this->store->create('website_media', ['status' => 'ready', 'path' => $files->write('image', 'website_media'), 'variants' => [['width' => 768, 'path' => $variant]]]);
        $this->store->create('invoices', ['number' => 'INV-1', 'pdf_path' => null]);
        $archive = $this->directory.'/firm.lcrm';
        $this->assertSame(6, app(Backup::class)->create($archive, self::PASSWORD)['files']);
        DB::table('crm_records')->delete();
        config(['crm.private_path' => $this->directory.'/restored-vault']);
        app(Backup::class)->restore($archive, self::PASSWORD);
        $this->assertSame('[{"type":"paragraph"}]', $files->read($published));
        $this->assertSame('resized image', $files->read($variant));
        config(['crm.private_path' => $this->directory.'/source-vault']);
        foreach ([['payslips', ['pdf_path' => 'financial/00000000-0000-0000-0000-000000000000.enc'], 'missing'], ['website_media', ['variants' => [['path' => 'website_media/00000000-0000-0000-0000-000000000000.enc']]], 'missing'], ['documents', ['path' => 'temporary/00000000-0000-0000-0000-000000000000.enc'], 'temporary']] as $i => [$collection, $data, $message]) {
            $record = $this->store->create($collection, $data);
            try {
                app(Backup::class)->create($this->directory.'/broken-'.$i.'.lcrm', self::PASSWORD);
                $this->fail('A broken private file reference was not detected.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
            $this->assertFileDoesNotExist($this->directory.'/broken-'.$i.'.lcrm');
            $this->store->delete($collection, $record['id']);
        }
    }

    public function test_tampering_wrong_passphrase_and_truncation_never_modify_the_destination(): void
    {
        $this->store->create('contacts', ['name' => 'One']);
        $archive = $this->directory.'/firm.lcrm';
        app(Backup::class)->create($archive, self::PASSWORD);
        $bytes = file_get_contents($archive);
        $tampered = $bytes;
        $tampered[80] = chr(ord($tampered[80]) ^ 1);
        file_put_contents($this->directory.'/tampered.lcrm', $tampered);
        file_put_contents($this->directory.'/truncated.lcrm', substr($bytes, 0, -20));
        DB::table('crm_records')->delete();
        foreach ([[$archive, 'incorrect-but-long-passphrase'], [$this->directory.'/tampered.lcrm', self::PASSWORD], [$this->directory.'/truncated.lcrm', self::PASSWORD]] as [$path, $password]) {
            try {
                app(Backup::class)->restore($path, $password);
                $this->fail('Damaged archive accepted.');
            } catch (\RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
            $this->assertSame(0, DB::table('crm_records')->count());
        }
    }

    public function test_restore_refuses_wrong_application_key_and_nonempty_destination(): void
    {
        $this->store->create('contacts', ['name' => 'Preserve me']);
        $archive = $this->directory.'/firm.lcrm';
        app(Backup::class)->create($archive, self::PASSWORD);
        try {
            app(Backup::class)->restore($archive, self::PASSWORD);
            $this->fail('Existing database overwritten.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('empty primary store', $error->getMessage());
        }
        $this->assertSame(1, DB::table('crm_records')->count());
        DB::table('crm_records')->delete();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        try {
            app(Backup::class)->restore($archive, self::PASSWORD);
            $this->fail('Wrong encryption key accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('APP_KEY does not match', $error->getMessage());
        }
        $this->assertSame(0, DB::table('crm_records')->count());
    }

    public function test_maintenance_and_job_lock_are_required_and_output_cannot_overwrite_an_archive(): void
    {
        $archive = $this->directory.'/firm.lcrm';
        $this->maintenance = false;
        try {
            app(Backup::class)->create($archive, self::PASSWORD);
            $this->fail('Live snapshot accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('artisan down', $error->getMessage());
        }
        $this->maintenance = true;
        $lock = fopen(storage_path('framework/crm-backup.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            app(Backup::class)->create($archive, self::PASSWORD);
            $this->fail('Concurrent snapshot accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Another backup', $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        file_put_contents($archive, 'original');
        try {
            app(Backup::class)->create($archive, self::PASSWORD);
            $this->fail('Existing archive overwritten.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('already exists', $error->getMessage());
        }
        $this->assertSame('original', file_get_contents($archive));
    }
}
