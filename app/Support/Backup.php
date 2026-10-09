<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Contracts\RecordStore;
use Illuminate\Support\Facades\Crypt;

/** Portable, authenticated, streaming snapshots. This service is exposed only by the CLI. */
final class Backup
{
    private const MAGIC = "LEGALCRM-BACKUP-1\n";

    private const MAX_FRAME = 4_194_304;

    private const FILE_CHUNK = 65_536;

    /** Fields the application writes vault references into ('*' is any key). Every other string is record data, never a file reference. */
    private const FILE_FIELDS = [
        'ai_runs' => ['result_path'],
        'content_revisions' => ['snapshot.blocks_path'],
        'documents' => ['path', 'blocks_path'],
        'invoices' => ['pdf_path'],
        'pages' => ['blocks_path', 'published_snapshot.blocks_path'],
        'payslips' => ['pdf_path'],
        'theme_uploads' => ['path'],
        'themes' => ['assets.*.path'],
        'website_fonts' => ['assets.*.path'],
        'website_media' => ['path', 'variants.*.path'],
    ];

    public function __construct(private RecordStore $store) {}

    public function create(string $target, string $passphrase): array
    {
        $this->maintenance();
        $this->password($passphrase);
        $lock = $this->lock();
        $scratch = $this->scratch();
        $output = null;
        try {
            $parent = realpath(dirname($target));
            if (! $parent || ! is_writable($parent)) {
                throw new \RuntimeException('The backup output directory must exist and be writable.');
            }
            $this->outsidePublic($parent);
            $target = $parent.'/'.basename($target);
            if (file_exists($target) || is_link($target)) {
                throw new \RuntimeException('The output already exists. Choose a new backup filename.');
            }
            $output = fopen($target, 'xb');
            if (! $output) {
                throw new \RuntimeException('Could not create the backup.');
            } chmod($target, 0600);
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = $this->derive($passphrase, $salt);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            sodium_memzero($key);
            $this->write($output, self::MAGIC.$salt.$header);
            $digest = hash_init('sha256');
            $counts = ['records' => 0, 'files' => 0, 'file_bytes' => 0, 'excluded_records' => 0];
            $send = function (array $event, bool $final = false) use ($output, &$state, $digest): void {
                $plain = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($plain) > self::MAX_FRAME - 100) {
                    throw new \RuntimeException('A record exceeds the portable snapshot size limit.');
                }
                if (! $final) {
                    hash_update($digest, $plain);
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                $this->write($output, pack('N', strlen($cipher)).$cipher);
            };
            $manifest = ['format' => 1, 'application_version' => config('crm.version'), 'created_at' => now()->toISOString(), 'key_fingerprint' => $this->keyFingerprint(), 'source_profile' => config('crm.store') === 'firestore' ? 'firestore' : config('database.default'), 'excluded' => ['temporary analyzer records and files', 'public analyzer jobs and verification emails', 'sessions and password-reset tokens', 'unreferenced vault files']];
            $send(['type' => 'manifest', 'data' => $manifest]);
            $cursor = null;
            do {
                $batch = $this->store->scan($cursor, 250);
                foreach ($batch['records'] as $entry) {
                    if ($this->excluded($entry)) {
                        $counts['excluded_records']++;

                        continue;
                    }
                    $this->validateRecord($entry);
                    $send(['type' => 'record', 'data' => $entry]);
                    $counts['records']++;
                    foreach ($this->fileReferences($entry['collection'], $entry['record']) as $path) {
                        $marker = $scratch.'/seen/'.hash('sha256', $path);
                        if (is_file($marker)) {
                            continue;
                        }
                        touch($marker);
                        chmod($marker, 0600);
                        $full = $this->vaultFile($path);
                        if (! is_file($full) || is_link($full)) {
                            throw new \RuntimeException('A referenced private file is missing: '.$path);
                        }
                        $file = fopen($full, 'rb');
                        if (! $file) {
                            throw new \RuntimeException('A private file cannot be read.');
                        }
                        try {
                            $size = filesize($full);
                            $hash = hash_init('sha256');
                            $offset = 0;
                            $send(['type' => 'file_begin', 'path' => $path, 'size' => $size]);
                            while (! feof($file)) {
                                $bytes = fread($file, self::FILE_CHUNK);
                                if ($bytes === false) {
                                    throw new \RuntimeException('Could not read a private file.');
                                }
                                if ($bytes === '') {
                                    break;
                                }
                                hash_update($hash, $bytes);
                                $send(['type' => 'file_chunk', 'offset' => $offset, 'data' => base64_encode($bytes)]);
                                $offset += strlen($bytes);
                            }
                            if ($offset !== $size) {
                                throw new \RuntimeException('A private file changed during backup.');
                            }
                            $send(['type' => 'file_end', 'sha256' => hash_final($hash)]);
                            $counts['files']++;
                            $counts['file_bytes'] += $size;
                        } finally {
                            fclose($file);
                        }
                    }
                }
                $next = $batch['cursor'];
                if ($next !== null && $next === $cursor) {
                    throw new \RuntimeException('Snapshot pagination did not advance.');
                }
                $cursor = $next;
            } while ($cursor !== null);
            $send(['type' => 'complete', 'counts' => $counts, 'sha256' => hash_final($digest)], true);
            fflush($output);
            if (function_exists('fsync')) {
                fsync($output);
            }
            fclose($output);
            $output = null;

            return ['path' => $target, 'manifest' => $manifest] + $counts;
        } catch (\Throwable $error) {
            if (is_resource($output)) {
                fclose($output);
                if (is_file($target)) {
                    unlink($target);
                }
            }
            throw $error;
        } finally {
            $this->removeTree($scratch);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function inspect(string $source, string $passphrase): array
    {
        $this->password($passphrase);
        $scratch = $this->scratch();
        try {
            return $this->readArchive($source, $passphrase, $scratch, false);
        } finally {
            $this->removeTree($scratch);
        }
    }

    public function restore(string $source, string $passphrase): array
    {
        $this->maintenance();
        $this->password($passphrase);
        $lock = $this->lock();
        $scratch = $this->scratch();
        $started = false;
        try {
            $this->emptyDestination();
            $summary = $this->readArchive($source, $passphrase, $scratch, true);
            if (($summary['manifest']['application_version'] ?? null) !== config('crm.version')) {
                throw new \RuntimeException('Restore using the same application release recorded in this backup, then run the supported upgrade procedure.');
            }
            if (! hash_equals($this->keyFingerprint(), $summary['manifest']['key_fingerprint'])) {
                throw new \RuntimeException('APP_KEY does not match this backup. Recover the original application key before restoring encrypted records and files.');
            }
            $this->emptyDestination();
            $started = true;
            $rows = fopen($scratch.'/records.enc', 'rb');
            $batch = [];
            $batchBytes = 0;
            try {
                while (($line = fgets($rows)) !== false) {
                    $plain = Crypt::decryptString(trim($line));
                    if ($batch && (count($batch) >= 100 || $batchBytes + strlen($plain) > 3_500_000)) {
                        $this->store->restore($batch);
                        $batch = [];
                        $batchBytes = 0;
                    }
                    $batch[] = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
                    $batchBytes += strlen($plain);
                }
                if ($batch) {
                    $this->store->restore($batch);
                }
            } finally {
                fclose($rows);
            }
            if (is_dir($scratch.'/files')) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scratch.'/files', \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    $path = substr($file->getPathname(), strlen($scratch.'/files/'));
                    $target = $this->vaultFile($path);
                    if (! is_dir(dirname($target))) {
                        mkdir(dirname($target), 0700, true);
                    }
                    if (file_exists($target) || ! rename($file->getPathname(), $target)) {
                        throw new \RuntimeException('Could not commit a restored private file.');
                    }
                    chmod($target, 0600);
                }
            }

            return $summary + ['restored' => true];
        } catch (\Throwable $error) {
            if ($started) {
                try {
                    $this->rollbackRestore($scratch);
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('Restore failed and cleanup was incomplete. Keep maintenance mode active, inspect the empty destination, and retry using a new database and vault.', previous: $error);
                }
            }
            throw $error;
        } finally {
            $this->removeTree($scratch);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readArchive(string $source, string $passphrase, string $scratch, bool $stage): array
    {
        if (! is_file($source) || is_link($source)) {
            throw new \RuntimeException('Backup archive is not a regular file.');
        }
        $input = fopen($source, 'rb');
        if (! $input) {
            throw new \RuntimeException('Cannot read the backup archive.');
        }
        flock($input, LOCK_SH);
        $recordOutput = null;
        $fileOutput = null;
        try {
            if ($this->read($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new \RuntimeException('Unrecognized backup format.');
            }
            $salt = $this->read($input, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $header = $this->read($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $key = $this->derive($passphrase, $salt);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            sodium_memzero($key);
            $digest = hash_init('sha256');
            $counts = ['records' => 0, 'files' => 0, 'file_bytes' => 0];
            $manifest = null;
            $file = null;
            if ($stage) {
                $recordOutput = fopen($scratch.'/records.enc', 'xb');
                chmod($scratch.'/records.enc', 0600);
            }
            while (! feof($input)) {
                $length = unpack('N', $this->read($input, 4))[1];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::MAX_FRAME) {
                    throw new \RuntimeException('Invalid encrypted backup frame.');
                }
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($input, $length));
                if ($opened === false) {
                    throw new \RuntimeException('Backup authentication failed. The passphrase is incorrect or the archive is damaged.');
                }
                [$plain, $tag] = $opened;
                $event = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                    throw new \RuntimeException('Invalid backup event.');
                }
                $type = $event['type'];
                if ($type === 'complete') {
                    if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL || $file || ! $manifest || ! hash_equals(hash_final($digest), $event['sha256'] ?? '') || fread($input, 1) !== '') {
                        throw new \RuntimeException('The backup is incomplete or its manifest checksum is invalid.');
                    }
                    foreach ($counts as $name => $value) {
                        if (($event['counts'][$name] ?? null) !== $value) {
                            throw new \RuntimeException('Backup manifest count mismatch.');
                        }
                    }
                    foreach (new \DirectoryIterator($scratch.'/refs') as $ref) {
                        if (! $ref->isDot() && ! is_file($scratch.'/seen/'.$ref->getFilename())) {
                            throw new \RuntimeException('Backup has a private file reference without corresponding data.');
                        }
                    }

                    return ['manifest' => $manifest, 'sha256' => $event['sha256']] + $event['counts'];
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new \RuntimeException('Unexpected final backup frame.');
                }
                hash_update($digest, $plain);
                if (! $manifest && $type !== 'manifest') {
                    throw new \RuntimeException('Backup manifest is missing.');
                }
                if ($file && ! in_array($type, ['file_chunk', 'file_end'], true)) {
                    throw new \RuntimeException('Unfinished backup file.');
                }
                switch ($type) {
                    case 'manifest':
                        if ($manifest || ($event['data']['format'] ?? null) !== 1 || ! preg_match('/^[a-f0-9]{64}$/', $event['data']['key_fingerprint'] ?? '')) {
                            throw new \RuntimeException('Invalid backup manifest.');
                        }
                        $manifest = $event['data'];
                        break;
                    case 'record':
                        $entry = $event['data'] ?? [];
                        $this->validateRecord($entry);
                        if ($this->excluded($entry)) {
                            throw new \RuntimeException('Backup unexpectedly includes temporary or session data.');
                        }
                        $marker = $scratch.'/records/'.hash('sha256', $entry['collection'].'/'.$entry['record']['id']);
                        if (file_exists($marker)) {
                            throw new \RuntimeException('Duplicate record in backup.');
                        } touch($marker);
                        foreach ($this->fileReferences($entry['collection'], $entry['record']) as $path) {
                            touch($scratch.'/refs/'.hash('sha256', $path));
                        }
                        if ($stage) {
                            $this->write($recordOutput, Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR))."\n");
                        }
                        $counts['records']++;
                        break;
                    case 'file_begin':
                        $path = $event['path'] ?? '';
                        $this->validatePath($path);
                        if (! is_int($event['size'] ?? null) || $event['size'] < 0 || $event['size'] > 1024 * 1024 * 1024) {
                            throw new \RuntimeException('Invalid backup file size.');
                        }
                        $marker = $scratch.'/seen/'.hash('sha256', $path);
                        if (file_exists($marker)) {
                            throw new \RuntimeException('Duplicate file in backup.');
                        } touch($marker);
                        if (! is_file($scratch.'/refs/'.hash('sha256', $path))) {
                            throw new \RuntimeException('Backup file has no record reference.');
                        }
                        $file = ['path' => $path, 'size' => $event['size'], 'read' => 0, 'hash' => hash_init('sha256')];
                        if ($stage) {
                            $target = $scratch.'/files/'.$path;
                            if (! is_dir(dirname($target))) {
                                mkdir(dirname($target), 0700, true);
                            } $fileOutput = fopen($target, 'xb');
                            chmod($target, 0600);
                        }
                        break;
                    case 'file_chunk':
                        if (! $file || ($event['offset'] ?? -1) !== $file['read'] || ! is_string($event['data'] ?? null)) {
                            throw new \RuntimeException('Invalid backup file sequence.');
                        }
                        $bytes = base64_decode($event['data'], true);
                        if ($bytes === false || strlen($bytes) > self::FILE_CHUNK || $file['size'] < $file['read'] + strlen($bytes)) {
                            throw new \RuntimeException('Invalid backup file chunk.');
                        }
                        hash_update($file['hash'], $bytes);
                        $file['read'] += strlen($bytes);
                        if ($stage) {
                            $this->write($fileOutput, $bytes);
                        } break;
                    case 'file_end':
                        if (! $file || $file['read'] !== $file['size'] || ! hash_equals(hash_final($file['hash']), $event['sha256'] ?? '')) {
                            throw new \RuntimeException('Private file checksum mismatch.');
                        }
                        if ($stage) {
                            fclose($fileOutput);
                            $fileOutput = null;
                        }
                        $counts['files']++;
                        $counts['file_bytes'] += $file['size'];
                        $file = null;
                        break;
                    default: throw new \RuntimeException('Unknown backup event type.');
                }
            }
            throw new \RuntimeException('Backup was truncated before its final authenticated manifest.');
        } finally {
            if (is_resource($fileOutput)) {
                fclose($fileOutput);
            }
            if (is_resource($recordOutput)) {
                fclose($recordOutput);
            }
            flock($input, LOCK_UN);
            fclose($input);
        }
    }

    private function rollbackRestore(string $scratch): void
    {
        $rows = fopen($scratch.'/records.enc', 'rb');
        try {
            while (($line = fgets($rows)) !== false) {
                $entry = json_decode(Crypt::decryptString(trim($line)), true, 512, JSON_THROW_ON_ERROR);
                $record = $entry['record'];
                $current = $this->store->get($entry['collection'], $record['id']);
                if ($current) {
                    if ($current != $record) {
                        throw new \RuntimeException('Restored records were modified concurrently.');
                    }
                    $this->store->delete($entry['collection'], $record['id'], $record['version']);
                }
                foreach ($this->fileReferences($entry['collection'], $record) as $path) {
                    $full = $this->vaultFile($path);
                    if (is_file($full)) {
                        unlink($full);
                    }
                }
            }
        } finally {
            fclose($rows);
        }
    }

    private function excluded(array $entry): bool
    {
        $collection = $entry['collection'] ?? '';
        $record = $entry['record'] ?? [];
        if (str_starts_with($collection, 'analyzer_') || in_array($collection, ['sessions', 'identity_tokens', 'password_resets', 'password_reset_tokens'], true)) {
            return true;
        }
        if ($collection === 'ai_runs' && ($record['context'] ?? '') === 'public') {
            return true;
        }
        if ($collection === 'jobs' && (($record['type'] ?? '') === 'analyzer.run' || preg_match('~/tools/(verify|results?)/~', $record['payload']['body'] ?? ''))) {
            return true;
        }
        if ($collection === 'budgets' && str_starts_with($record['id'] ?? '', 'public-')) {
            return true;
        }

        return false;
    }

    private function fileReferences(string $collection, array $record): array
    {
        $paths = [];
        foreach (self::FILE_FIELDS[$collection] ?? [] as $field) {
            foreach ($this->fieldValues($record, explode('.', $field)) as $value) {
                if ($value !== null && $value !== '') {
                    $this->validatePath(is_string($value) ? $value : '');
                    $paths[$value] = $value;
                }
            }
        }

        return array_values($paths);
    }

    private function fieldValues(mixed $value, array $keys): array
    {
        if ($keys === []) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }
        $key = array_shift($keys);
        $children = $key === '*' ? array_values($value) : (array_key_exists($key, $value) ? [$value[$key]] : []);

        return array_merge([], ...array_map(fn ($child) => $this->fieldValues($child, $keys), $children));
    }

    private function validateRecord(array $entry): void
    {
        $record = $entry['record'] ?? null;
        if (! is_string($entry['collection'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $entry['collection']) || ! is_array($record) || ! is_string($record['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_.@-]{1,128}$/D', $record['id']) || ! is_int($record['version'] ?? null) || $record['version'] < 1 || ! is_string($record['created_at'] ?? null) || ! is_string($record['updated_at'] ?? null) || strtotime($record['created_at']) === false || strtotime($record['updated_at']) === false) {
            throw new \RuntimeException('Invalid portable record metadata.');
        }
    }

    private function validatePath(string $path): void
    {
        if (! preg_match('~^[a-z_]+/[a-f0-9-]{36}\.enc$~D', $path) || str_starts_with($path, 'temporary/')) {
            throw new \RuntimeException('Invalid or temporary private file in backup.');
        }
    }

    private function vaultFile(string $path): string
    {
        $this->validatePath($path);
        $vault = rtrim(config('crm.private_path'), '/');
        if (is_link($vault) || is_link($vault.'/'.dirname($path))) {
            throw new \RuntimeException('Symlinked vault directories are not supported.');
        }

        return $vault.'/'.$path;
    }

    private function emptyDestination(): void
    {
        $cursor = null;
        do {
            $page = $this->store->scan($cursor, 1);
            if ($page['records']) {
                throw new \RuntimeException('Restore requires an empty primary store. Use a new database or Firestore database.');
            } $cursor = $page['cursor'];
        } while ($cursor !== null);
        $vault = config('crm.private_path');
        if (is_link($vault)) {
            throw new \RuntimeException('The destination vault cannot be a symbolic link.');
        }
        if (is_dir($vault)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($vault, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if (! $file->isDir() || $file->isLink()) {
                    throw new \RuntimeException('Restore requires an empty private vault.');
                }
            }
        }
    }

    private function maintenance(): void
    {
        if (! app()->isDownForMaintenance()) {
            throw new \RuntimeException('Run php artisan down and stop background workers before creating or restoring a consistent snapshot.');
        }
    }

    private function password(string $passphrase): void
    {
        if (strlen($passphrase) < 16) {
            throw new \RuntimeException('Use a backup passphrase with at least 16 characters.');
        } if (! extension_loaded('sodium')) {
            throw new \RuntimeException('The sodium extension is required for authenticated backup encryption.');
        }
    }

    private function keyFingerprint(): string
    {
        if (! config('app.key')) {
            throw new \RuntimeException('APP_KEY is required.');
        }

        return hash('sha256', config('app.key'));
    }

    private function derive(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private function outsidePublic(string $path): void
    {
        $public = realpath(public_path());
        if ($public && ($path === $public || str_starts_with($path, $public.'/'))) {
            throw new \RuntimeException('Backup output must be outside the public web root.');
        }
    }

    private function lock()
    {
        $lock = fopen(storage_path('framework/crm-backup.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another backup or restore is running.');
        }

        return $lock;
    }

    private function scratch(): string
    {
        $base = storage_path('app/backup_work');
        if (! is_dir($base)) {
            mkdir($base, 0700, true);
        } $path = $base.'/'.bin2hex(random_bytes(16));
        mkdir($path, 0700);
        foreach (['seen', 'refs', 'records'] as $child) {
            mkdir($path.'/'.$child, 0700);
        }

        return $path;
    }

    private function write($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Backup storage write failed.');
            } $offset += $written;
        }
    }

    private function read($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                break;
            } $bytes .= $chunk;
        } if (strlen($bytes) !== $length) {
            throw new \RuntimeException('The backup archive is truncated.');
        }

        return $bytes;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        } $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        } rmdir($path);
    }
}
