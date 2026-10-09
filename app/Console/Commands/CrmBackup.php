<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Console\Commands;

use App\Support\Backup;
use Illuminate\Console\Command;

class CrmBackup extends Command
{
    protected $signature = 'crm:backup {action : create, verify or restore} {path : Absolute archive output/input path}';

    protected $description = 'Create, verify or restore an encrypted portable database and private-file snapshot.';

    public function handle(Backup $backup): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['create', 'verify', 'restore'], true)) {
            $this->error('Choose create, verify or restore.');

            return self::FAILURE;
        }
        $passphrase = getenv('CRM_BACKUP_PASSPHRASE') ?: '';
        if (! $passphrase && $this->input->isInteractive()) {
            $passphrase = (string) $this->secret('Backup passphrase (at least 16 characters; keep it outside this server)');
        }
        if (! $passphrase) {
            $this->error('Supply CRM_BACKUP_PASSPHRASE through the process environment or use the interactive prompt.');

            return self::FAILURE;
        }
        try {
            $method = $action === 'verify' ? 'inspect' : $action;
            $result = $backup->{$method}($this->argument('path'), $passphrase);
            $this->info(ucfirst($action).' completed: '.$result['records'].' records, '.$result['files'].' private files, '.$result['file_bytes'].' encrypted file bytes.');
            if ($action === 'restore') {
                $this->warn('Maintenance mode remains active. Verify access, documents, integrations and jobs before reopening the application.');
            }
            if ($action === 'create') {
                $this->line('Verify this archive and copy it to separately controlled encrypted storage. Keep APP_KEY and the backup passphrase in your recovery vault.');
            }

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($passphrase);
            }
        }
    }
}
