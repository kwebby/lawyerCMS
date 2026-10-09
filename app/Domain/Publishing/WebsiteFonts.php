<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use Illuminate\Validation\ValidationException;

final class WebsiteFonts
{
    public function __construct(private RecordStore $store) {}

    public function catalog(?string $query = null): array
    {
        $catalog = json_decode(file_get_contents(public_path('fonts/website/catalog.json')), true, 512, JSON_THROW_ON_ERROR)['fonts'];
        foreach ($this->store->query('website_fonts', ['status' => 'ready'], 100) as $record) {
            $catalog[] = $record['font'];
        }
        $query = mb_strtolower(trim($query ?? ''));

        return array_values(array_filter($catalog, fn (array $font): bool => $query === '' || str_contains(mb_strtolower($font['name'].' '.$font['category'].' '.implode(' ', $font['languages'])), $query)));
    }

    public function find(string $id): array
    {
        foreach ($this->catalog() as $font) {
            if ($font['id'] === $id) {
                return $font;
            }
        }
        throw ValidationException::withMessages(['font' => 'Select a font from the installed library.']);
    }

    public function family(string $id): string
    {
        return $this->find($id)['css_family'];
    }

    public function css(array|string $ids, ?string $bodyId = null): string
    {
        $css = '';
        if (is_string($ids)) {
            $bodyId ??= $ids;
            $css = ':root{--heading-font:'.$this->family($ids).';--body-font:'.$this->family($bodyId).';}';
            $ids = [$ids, $bodyId];
        }
        foreach (array_unique($ids) as $id) {
            $font = $this->find($id);
            foreach ($font['files'] as $file) {
                $css .= "@font-face{font-family:'".$font['name']."';font-style:normal;font-weight:".$file['weight'].";font-display:swap;src:url('".$file['url']."') format('woff2');unicode-range:".$file['unicode_range'].";}\n";
            }
        }

        return $css;
    }
}
