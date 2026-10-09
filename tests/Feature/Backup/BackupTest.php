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
