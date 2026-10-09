<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

final class HostingCapabilities
{
    public function memoryBytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        if (! preg_match('/^(\d+)\s*([KMG]?)$/iD', $limit, $match)) {
            return 0;
        }

        return (int) $match[1] * match (strtoupper($match[2])) {
            'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1,
        };
    }

    public function privatePath(string $path, string $publicRoot): bool
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return false;
        }
        $ancestor = $path;
        $suffix = [];
        while (! file_exists($ancestor)) {
            $part = basename($ancestor);
            if (in_array($part, ['.', '..', ''], true)) {
                return false;
            }
            array_unshift($suffix, $part);
            $next = dirname($ancestor);
            if ($next === $ancestor) {
                return false;
            }
            $ancestor = $next;
        }
        $resolved = realpath($ancestor);
        $public = realpath($publicRoot);
        if (! $resolved || ! $public || ! is_dir($resolved) || ! is_writable($resolved)) {
            return false;
        }
        $resolved = rtrim($resolved, DIRECTORY_SEPARATOR).($suffix ? DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $suffix) : '');
        $public = rtrim($public, DIRECTORY_SEPARATOR);

        return $resolved !== $public && ! str_starts_with($resolved, $public.DIRECTORY_SEPARATOR);
    }

    public function inspect(?string $profile = null): array
    {
        $profile ??= config('crm.store') === 'firestore' ? 'firestore' : config('database.default');
        $checks = [
            'PHP 8.3+' => version_compare(PHP_VERSION, '8.3.0', '>='),
            '64-bit PHP' => PHP_INT_SIZE === 8,
            'PHP memory at least 256 MB' => $this->memoryBytes(ini_get('memory_limit')) >= 256 * 1024 ** 2,
            'Private storage has a writable parent outside public root' => $this->privatePath(config('crm.private_path'), public_path()),
        ];
        foreach (['openssl', 'sodium', 'mbstring', 'intl', 'bcmath', 'curl', 'fileinfo', 'dom', 'xml', 'zip', 'gd'] as $extension) {
            $checks['PHP '.$extension] = extension_loaded($extension);
        }
        if ($profile !== 'firestore') {
            $driver = in_array($profile, ['pgsql', 'supabase'], true) ? 'pgsql' : ($profile === 'sqlite' ? 'sqlite' : 'mysql');
            $checks['PHP PDO '.$driver] = extension_loaded('pdo_'.$driver);
        } else {
            $checks['Firestore project configured'] = (bool) config('crm.firestore.project');
            $credentials = config('crm.firestore.credentials');
            $checks['Readable private Firestore credentials'] = is_string($credentials) && is_file($credentials) && is_readable($credentials) && ! str_starts_with(realpath($credentials), realpath(public_path()).DIRECTORY_SEPARATOR);
        }
        if (app()->environment('production')) {
            $checks['HTTPS canonical application URL'] = str_starts_with(config('app.url'), 'https://');
            $checks['Debug disabled'] = ! config('app.debug');
            $checks['Secure session cookies'] = (bool) config('session.secure');
            $checks['Staff MFA required'] = (bool) config('crm.require_mfa');
        }

        return $checks;
    }
}
