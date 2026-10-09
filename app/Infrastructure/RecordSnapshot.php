<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Infrastructure;

/** Validation shared by privileged maintenance-only snapshot importers. */
final class RecordSnapshot
{
    public static function batch(array $batch): array
    {
        if (count($batch) > 200 || strlen(json_encode($batch, JSON_THROW_ON_ERROR)) > 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('Restore at most 200 records and 4 MiB per batch.');
        }
        $seen = [];
        foreach ($batch as $entry) {
            if (! is_array($entry) || ! is_string($entry['collection'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $entry['collection'])) {
                throw new \InvalidArgumentException('Invalid snapshot collection.');
            }
            $record = $entry['record'] ?? null;
            if (! is_array($record) || ! is_string($record['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_.@-]{1,128}$/D', $record['id']) || ! is_int($record['version'] ?? null) || $record['version'] < 1) {
                throw new \InvalidArgumentException('Invalid snapshot record identity or version.');
            }
            foreach (['created_at', 'updated_at'] as $field) {
                if (! is_string($record[$field] ?? null) || strlen($record[$field]) > 32 || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $record[$field])) {
                    throw new \InvalidArgumentException('Invalid snapshot timestamp.');
                }
            }
            $key = $entry['collection'].'/'.$record['id'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Duplicate record in restore batch.');
            }
            $seen[$key] = true;
        }

        return $batch;
    }

    public static function cursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        if (strlen($cursor) > 32768 || ($decoded = base64_decode($cursor, true)) === false) {
            throw new \InvalidArgumentException('Invalid snapshot cursor.');
        }
        $state = json_decode($decoded, true, 20, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new \InvalidArgumentException('Invalid snapshot cursor.');
        }

        return $state;
    }

    public static function encode(array $state): string
    {
        return base64_encode(json_encode($state, JSON_THROW_ON_ERROR));
    }
}
