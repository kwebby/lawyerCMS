<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Console\Commands;

use App\Support\JobRunner;
use Illuminate\Console\Command;

class CrmTick extends Command
{
    protected $signature = 'crm:tick {--budget=45 : Maximum runtime seconds}';

    protected $description = 'Process leased durable jobs and expire temporary analyzer files.';

    public function handle(JobRunner $runner): int
    {
        $lock = fopen(storage_path('framework/crm-backup.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->line('Another scheduler or backup owns the lease.');

            return self::SUCCESS;
        }
        try {
            $this->line(json_encode($runner->tick(max(25, min(55, (int) $this->option('budget'))))));

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
