<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\Audit;
use App\Support\PrivateFiles;
use App\Support\UploadScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class WebsiteMedia
{
    public function __construct(private RecordStore $store, private PrivateFiles $files, private UploadScanner $scanner, private Audit $audit) {}

    public function all(): array
    {
        return array_map($this->present(...), $this->store->query('website_media', [], 1000));
    }

    public function upload(UploadedFile $image, array $metadata, string $actor): array
    {
        if (! $image->isValid() || $image->getSize() > 10 * 1024 * 1024) {
            $this->invalid('Upload a valid image no larger than 10 MB.');
        }
        $bytes = file_get_contents($image->getRealPath());
        $dimensions = $this->dimensions($bytes);
        $name = mb_substr(preg_replace('/[^\pL\pN ._-]/u', '', basename($image->getClientOriginalName())), 0, 160) ?: 'Website image';
        $path = $this->files->write($bytes, 'website_media');
        try {
            $record = $this->store->transaction(function () use ($path, $bytes, $dimensions, $name, $metadata, $actor): array {
                $record = $this->store->create('website_media', [...$this->metadata($metadata), 'name' => $name, 'status' => 'quarantined', 'path' => $path, 'sha256' => hash('sha256', $bytes), 'mime' => $dimensions['mime'], 'width' => $dimensions[0], 'height' => $dimensions[1], 'bytes' => strlen($bytes), 'created_by' => $actor, 'message' => 'Awaiting a clean malware scan.']);
                $this->audit->log($actor, 'website.media.uploaded', 'website_media', $record['id']);

                return $record;
            });
        } catch (\Throwable $error) {
            $this->files->delete($path);
            throw $error;
        }

        return $this->scan($record, $actor);
    }

    public function update(string $id, array $data, string $actor): array
    {
        return $this->store->transaction(function () use ($id, $data, $actor): array {
            $record = $this->store->get('website_media', $id);
            abort_unless($record !== null, 404);
            $record = $this->store->put('website_media', $id, array_replace($record, $this->metadata($data, false)), $data['expected_version']);
            $this->audit->log($actor, 'website.media.metadata_updated', 'website_media', $id);

            return $this->present($record);
        });
    }

    public function retry(string $id, string $actor): array
    {
        $record = $this->store->get('website_media', $id);
        abort_unless($record && $record['status'] === 'quarantined', 404);

        return $this->scan($record, $actor);
    }

    public function delete(string $id, int $expectedVersion, string $actor): void
    {
        $paths = $this->store->transaction(function () use ($id, $expectedVersion, $actor): array {
            $state = $this->store->get('settings', 'website-state') ?? [];
            $record = $this->store->get('website_media', $id);
            abort_unless($record !== null, 404);
            if ($this->references($state['draft'] ?? [], $id) || $this->references($state['published']['document'] ?? [], $id)) {
                $this->invalid('This image is used by the website draft or published website. Remove those references before deleting.');
            }
            foreach ($state['history'] ?? [] as $entry) {
                $revision = $this->store->get('website_revisions', $entry['id']);
                if ($this->references($revision['document'] ?? [], $id)) {
                    $this->invalid('This image belongs to a retained published revision and must remain available for rollback.');
                }
            }
            $this->store->delete('website_media', $id, $expectedVersion);
            $this->audit->log($actor, 'website.media.deleted', 'website_media', $id);

            return [$record['path'], ...array_column($record['variants'] ?? [], 'path')];
        });
        foreach ($paths as $path) {
            $this->files->delete($path);
        }
    }

    public function contents(string $id, bool $public = false, ?int $width = null): array
    {
        $record = $this->store->get('website_media', $id);
        abort_unless($record && $record['status'] === 'ready', 404);
        if ($public) {
            $published = app(Website::class)->published();
            abort_unless($published && $this->references($published, $id, true), 404);
        }

        $path = $record['path'];
        if ($width !== null) {
            abort_unless(in_array($width, [768, 1280], true), 404);
            $variant = collect($record['variants'] ?? [])->first(fn (array $variant): bool => $variant['width'] === $width);
            abort_unless($variant !== null, 404);
            $path = $variant['path'];
        }

        return ['contents' => $this->files->read($path), 'mime' => $record['mime']];
    }

    /** Only application-owned image fields can grant public delivery or retain an asset. */
    public function references(array $document, string $id, bool $visibleOnly = false): bool
    {
        if ($visibleOnly && (($document['visible'] ?? true) === false || ($document['enabled'] ?? true) === false)) {
            return false;
        }
        foreach ($document as $key => $value) {
            if (in_array($key, ['image_id', 'logo_id'], true) && $value === $id) {
                return true;
            }
            if (is_array($value) && $this->references($value, $id, $visibleOnly)) {
                return true;
            }
        }

        return false;
    }

    public function present(array $record): array
    {
        $data = array_intersect_key($record, array_flip(['id', 'version', 'name', 'status', 'mime', 'width', 'height', 'bytes', 'alt', 'caption', 'rights', 'focal_x', 'focal_y', 'created_at', 'updated_at', 'message']));
        $ready = $record['status'] === 'ready';

        $sources = $ready ? array_map(fn (array $variant): array => ['width' => $variant['width'], 'height' => $variant['height'], 'url' => '/website/media/'.$record['id'].'?width='.$variant['width'], 'preview_url' => '/api/v1/website/media/'.$record['id'].'/preview?width='.$variant['width']], $record['variants'] ?? []) : [];

        return $data + ['focal_x' => 50, 'focal_y' => 50, 'url' => $ready ? '/website/media/'.$record['id'] : null, 'preview_url' => $ready ? '/api/v1/website/media/'.$record['id'].'/preview' : null, 'sources' => $sources];
    }

    private function scan(array $record, string $actor): array
    {
        $bytes = $this->files->read($record['path']);
        try {
            $clean = $this->scanner->scan($bytes, $record['name']);
        } catch (\Throwable) {
            return $this->saveScan($record, ['status' => 'quarantined', 'message' => 'The scanner is unavailable. This image stays quarantined until a successful retry.'], $actor);
        }
        if (! $clean) {
            return $this->saveScan($record, ['status' => 'rejected', 'message' => 'The malware scanner rejected this image.'], $actor);
        }
        if (! function_exists('imagecreatefromstring')) {
            return $this->saveScan($record, ['status' => 'quarantined', 'message' => 'PHP GD is required to safely prepare website images. Enable it and retry.'], $actor);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return $this->saveScan($record, ['status' => 'rejected', 'message' => 'The image could not be decoded. Upload a complete raster image.'], $actor);
        }
        $paths = [];
        try {
            $primary = $this->encode($image, $record['mime'], min(1920, imagesx($image)));
            $primary['path'] = $this->files->write($primary['contents'], 'website_media');
            $paths[] = $primary['path'];
            unset($primary['contents']);
            $variants = [];
            foreach ([768, 1280] as $width) {
                if ($width >= $primary['width']) {
                    continue;
                }
                $variant = $this->encode($image, $record['mime'], $width);
                $variant['path'] = $this->files->write($variant['contents'], 'website_media');
                $paths[] = $variant['path'];
                unset($variant['contents']);
                $variants[] = $variant;
            }
            $saved = $this->saveScan($record, [...$primary, 'variants' => $variants, 'status' => 'ready', 'message' => 'Scanned and ready to use.'], $actor);
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                $this->files->delete($path);
            }
            if ($error instanceof \UnexpectedValueException) {
                return $this->saveScan($record, ['status' => 'rejected', 'message' => 'The image could not be prepared within the 10 MB limit.'], $actor);
            }
            throw $error;
        }
        $this->files->delete($record['path']);

        return $saved;
    }

    /** Resize only after a successful malware scan, stripping embedded metadata and trailing content. */
    private function encode(\GdImage $source, string $mime, int $width): array
    {
        $height = max(1, (int) round(imagesy($source) * $width / imagesx($source)));
        $image = $source;
        if ($width !== imagesx($source)) {
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            if (! imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))) {
                throw new \UnexpectedValueException('Image resize failed.');
            }
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        try {
            $encoded = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 88),
                'image/png' => imagepng($image, null, 6),
                'image/webp' => function_exists('imagewebp') && imagewebp($image, null, 88),
                default => false,
            };
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (! $encoded || ! is_string($contents) || $contents === '' || strlen($contents) > 10 * 1024 * 1024) {
            throw new \UnexpectedValueException('Image encoding failed.');
        }

        return ['contents' => $contents, 'width' => $width, 'height' => $height, 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }

    private function saveScan(array $record, array $changes, string $actor): array
    {
        return $this->store->transaction(function () use ($record, $changes, $actor): array {
            $saved = $this->store->put('website_media', $record['id'], array_replace($record, $changes), $record['version']);
            $this->audit->log($actor, 'website.media.scanned', 'website_media', $record['id'], ['status' => $saved['status']]);

            return $this->present($saved);
        });
    }

    private function dimensions(string $bytes): array
    {
        $dimensions = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! $dimensions || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $mime !== $dimensions['mime']) {
            $this->invalid('Use a genuine JPEG, PNG, or WebP image. SVG and executable files are not supported.');
        }
        if ($dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 8000 || $dimensions[1] > 8000 || $dimensions[0] * $dimensions[1] > 8000000) {
            $this->invalid('Images must be at most 8 megapixels and 8,000 pixels on either side.');
        }

        return $dimensions;
    }

    private function metadata(array $data, bool $defaults = true): array
    {
        $validated = validator($data, ['alt' => ['sometimes', 'nullable', 'string', 'max:500'], 'caption' => ['sometimes', 'nullable', 'string', 'max:1000'], 'rights' => ['sometimes', 'nullable', 'string', 'max:1000'], 'focal_x' => ['sometimes', 'integer', 'between:0,100'], 'focal_y' => ['sometimes', 'integer', 'between:0,100']])->validate();
        $values = [];
        foreach ($validated as $key => $value) {
            $values[$key] = in_array($key, ['focal_x', 'focal_y'], true) ? (int) $value : trim($value ?? '');
        }

        return $defaults ? array_replace(['alt' => '', 'caption' => '', 'rights' => '', 'focal_x' => 50, 'focal_y' => 50], $values) : $values;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
