<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class PrivateFiles
{
    private function full(string $path): string
    {
        if (! preg_match('#^[a-z_]+/[a-f0-9-]{36}\.enc$#D', $path)) {
            throw new \InvalidArgumentException('Invalid private file reference.');
        }

        return rtrim(config('crm.private_path'), '/').'/'.$path;
    }

    public function write(string $contents, string $category = 'documents'): string
    {
        if (! preg_match('/^[a-z_]+$/D', $category)) {
            throw new \InvalidArgumentException('Invalid file category.');
        }
        $path = $category.'/'.Str::uuid().'.enc';
        $full = $this->full($path);
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0700, true);
        }
        $temp = $full.'.tmp';
        if (file_put_contents($temp, Crypt::encryptString($contents), LOCK_EX) === false) {
            throw new \RuntimeException('Private storage write failed.');
        }
        chmod($temp, 0600);
        if (! rename($temp, $full)) {
            throw new \RuntimeException('Private storage commit failed.');
        }

        return $path;
    }

    public function read(string $path): string
    {
        $contents = file_get_contents($this->full($path));
        if ($contents === false) {
            throw new \RuntimeException('Private file unavailable.');
        }

        return Crypt::decryptString($contents);
    }

    public function delete(string $path): void
    {
        $full = $this->full($path);
        if (is_file($full)) {
            unlink($full);
        }
    }
}
