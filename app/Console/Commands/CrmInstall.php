<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Console\Commands;

use App\Contracts\RecordStore;
use App\Support\HostingCapabilities;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrmInstall extends Command
{
    protected $signature = 'crm:install {--profile= : mysql, pgsql, supabase or firestore; credentials must already be in .env} {--check : Check capabilities without changes}';

    protected $description = 'Validate native hosting and initialize the selected primary store.';

    public function handle(): int
    {
        $profile = $this->option('profile');
        if ($profile && ! in_array($profile, ['mysql', 'pgsql', 'supabase', 'firestore'], true)) {
            $this->error('Unsupported production database profile.');

            return self::FAILURE;
        }
        $checks = app(HostingCapabilities::class)->inspect($profile);
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? 'PASS ' : 'FAIL ').$name);
        }
        if (in_array(false, $checks, true)) {
            return self::FAILURE;
        }
        $memory = ini_get('memory_limit');
        $this->line('PHP memory limit: '.$memory.'; document processing requires 256 MB minimum, 512 MB recommended.');
        $this->line('Install a one-minute PHP CLI cron and enable HTTPS. These must be verified on the target host.');
        if ($this->option('check')) {
            return self::SUCCESS;
        }
        if ($profile) {
            $connection = in_array($profile, ['pgsql', 'supabase']) ? 'pgsql' : 'mysql';
            $values = ['CRM_STORE' => $profile === 'firestore' ? 'firestore' : 'sql'];
            if ($profile !== 'firestore') {
                $values['DB_CONNECTION'] = $connection;
            }
            $this->environment($values);
            config(['crm.store' => $values['CRM_STORE']]);
            if ($profile !== 'firestore') {
                config(['database.default' => $connection]);
            }
            $this->laravel->forgetInstance(RecordStore::class);
        }
        if (config('crm.store') !== 'firestore' && $this->call('migrate', ['--force' => true]) !== 0) {
            return self::FAILURE;
        }
        $store = app(RecordStore::class);
        $store->transaction(function () use ($store) {
            $test = $store->create('system', ['checked_at' => now()->toISOString()]);
            $store->delete('system', $test['id'], $test['version']);
        });
        if ($store->get('settings', 'installation')) {
            $this->info('Installation is already initialized.');

            return self::SUCCESS;
        }
        if (! is_dir(config('crm.private_path')) && ! mkdir(config('crm.private_path'), 0700, true)) {
            $this->error('Unable to create private storage.');

            return self::FAILURE;
        }
        $token = Str::random(64);
        $this->environment(['CRM_BOOTSTRAP_TOKEN' => $token]);
        $this->info('Primary store passed its write/transaction check.');
        $this->line('Open '.config('app.url').'/setup and enter this one-time installation token:');
        $this->line($token);
        $this->warn('Keep this token private. The bootstrap is disabled after the first owner is created.');

        return self::SUCCESS;
    }

    private function environment(array $values): void
    {
        $path = base_path('.env');
        $contents = file_get_contents($path);
        foreach ($values as $name => $value) {
            $line = $name.'='.$value;
            $pattern = '/^'.preg_quote($name, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents) ? preg_replace($pattern, $line, $contents) : $contents."\n".$line;
        }
        file_put_contents($path, $contents."\n", LOCK_EX);
        chmod($path, 0600);
        $this->callSilent('config:clear');
    }
}
