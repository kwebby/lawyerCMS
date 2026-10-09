<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use Illuminate\Validation\ValidationException;

/** The browser and all server renderers share this explicit, non-executable subset. */
final class BlockDocument
{
    public const TYPES = ['paragraph', 'heading', 'bulletListItem', 'numberedListItem', 'checkListItem', 'quote', 'table', 'image', 'file', 'citation', 'mergeField', 'question', 'pageBreak'];

    private int $count = 0;

    public function validate(array $blocks): array
    {
        $this->count = 0;
        if (! array_is_list($blocks) || strlen(json_encode($blocks, JSON_THROW_ON_ERROR)) > 2_000_000) {
            $this->invalid('The document must be a list under 2 MB.');
        }

        return $this->validateBlocks($blocks, 0);
    }

    private function validateBlocks(array $blocks, int $depth): array
    {
        if ($depth > 8) {
            $this->invalid('Documents may nest at most eight levels.');
        }
        $result = [];
        foreach ($blocks as $block) {
            if (++$this->count > 1000 || ! is_array($block) || ! in_array($block['type'] ?? null, self::TYPES, true)) {
                $this->invalid('Unsupported block type, or too many blocks.');
            }
            foreach (array_keys($block) as $key) {
                if (! in_array($key, ['id', 'type', 'props', 'content', 'children'], true)) {
                    $this->invalid('Unsupported block field: '.$key);
                }
            }
            $type = $block['type'];
            $props = $block['props'] ?? [];
            if (! is_array($props)) {
                $this->invalid('Invalid block properties.');
            }
            $allowed = ['textColor', 'backgroundColor', 'textAlignment'];
            $allowed = array_merge($allowed, match ($type) {
                'heading' => ['level', 'isToggleable'],
                'numberedListItem' => ['start'],
                'checkListItem' => ['checked'],
                'image' => ['url', 'name', 'caption', 'showPreview', 'previewWidth', 'fileId'],
                'file' => ['url', 'name', 'caption', 'fileId'],
                'citation' => ['source', 'url', 'pinpoint'],
                'mergeField' => ['field', 'fallback'],
                'question' => ['resolved'],
                default => [],
            });
            foreach ($props as $key => $value) {
                if (! in_array($key, $allowed, true) || (! is_scalar($value) && $value !== null)) {
                    $this->invalid('Unsupported property: '.$key);
                }
                if (is_string($value) && mb_strlen($value) > 3000) {
                    $this->invalid('Block property is too long.');
                }
            }
            foreach (['textColor', 'backgroundColor'] as $key) {
                if (isset($props[$key]) && ! preg_match('/^(default|gray|brown|red|orange|yellow|green|blue|purple|pink|#[0-9a-f]{6})$/iD', (string) $props[$key])) {
                    $this->invalid('Unsupported text color.');
                }
            }
            foreach (['checked', 'showPreview', 'isToggleable', 'resolved'] as $boolean) {
                if (isset($props[$boolean]) && ! is_bool($props[$boolean])) {
                    $this->invalid('Invalid boolean property.');
                }
            }
            if (isset($props['start']) && (! is_int($props['start']) || $props['start'] < 1 || $props['start'] > 100000)) {
                $this->invalid('Invalid list start.');
            }
            if (isset($props['textAlignment']) && ! in_array($props['textAlignment'], ['left', 'center', 'right', 'justify'], true)) {
                $this->invalid('Invalid alignment.');
            }
            if ($type === 'heading' && ! in_array($props['level'] ?? 2, [1, 2, 3, 4, 5, 6], true)) {
                $this->invalid('Invalid heading level.');
            }
            if (isset($props['url']) && $props['url'] !== '' && ! $this->safeUrl((string) $props['url'])) {
                $this->invalid('Unsafe block URL.');
            }
            if (isset($props['fileId']) && ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $props['fileId'])) {
                $this->invalid('Invalid file identifier.');
            }
            if (isset($props['previewWidth']) && (! is_numeric($props['previewWidth']) || $props['previewWidth'] < 1 || $props['previewWidth'] > 3000)) {
                $this->invalid('Invalid image width.');
            }
            if ($type === 'mergeField' && ! preg_match('/^[a-z][a-z0-9_.]{0,79}$/iD', (string) ($props['field'] ?? ''))) {
                $this->invalid('Invalid merge field.');
            }
            if ($type === 'table') {
                $content = $this->validateTable($block['content'] ?? []);
            } elseif (in_array($type, ['image', 'file', 'pageBreak'], true)) {
                if (! empty($block['content'])) {
                    $this->invalid('This block does not accept inline content.');
                }
                $content = null;
            } else {
                $content = $this->validateInline($block['content'] ?? []);
            }
            $children = $block['children'] ?? [];
            if (! is_array($children) || ! array_is_list($children)) {
                $this->invalid('Invalid child blocks.');
            }
            $item = ['type' => $type, 'props' => $props, 'children' => $this->validateBlocks($children, $depth + 1)];
            if (isset($block['id'])) {
                if (! is_string($block['id']) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $block['id'])) {
                    $this->invalid('Invalid block identifier.');
                }
                $item['id'] = $block['id'];
            }
            if ($content !== null) {
                $item['content'] = $content;
            }
            $result[] = $item;
        }

        return $result;
    }

    private function validateInline(mixed $content, bool $allowLinks = true): array
    {
        if (is_string($content)) {
            return [['type' => 'text', 'text' => $content, 'styles' => []]];
        }
        if (! is_array($content) || ! array_is_list($content) || count($content) > 2000) {
            $this->invalid('Invalid inline content.');
        }
        $result = [];
        foreach ($content as $span) {
            if (! is_array($span)) {
                $this->invalid('Invalid inline content.');
            }
            if (($span['type'] ?? null) === 'text') {
                if (! is_string($span['text'] ?? null) || mb_strlen($span['text']) > 100_000) {
                    $this->invalid('Invalid text.');
                }
                $styles = $span['styles'] ?? [];
                if (! is_array($styles)) {
                    $this->invalid('Invalid styles.');
                }
                foreach ($styles as $key => $value) {
                    if (in_array($key, ['bold', 'italic', 'underline', 'strike', 'code'], true)) {
                        if (! is_bool($value)) {
                            $this->invalid('Text style must be boolean.');
                        }
                    } elseif (in_array($key, ['textColor', 'backgroundColor'], true)) {
                        if (! is_string($value) || ! preg_match('/^(default|gray|brown|red|orange|yellow|green|blue|purple|pink|#[0-9a-f]{6})$/iD', $value)) {
                            $this->invalid('Invalid text color.');
                        }
                    } else {
                        $this->invalid('Unsupported text style.');
                    }
                }
                $result[] = ['type' => 'text', 'text' => $span['text'], 'styles' => $styles];
            } elseif (($span['type'] ?? null) === 'link' && $allowLinks) {
                if (! is_string($span['href'] ?? null) || ! $this->safeUrl($span['href'])) {
                    $this->invalid('Unsafe link.');
                }
                $result[] = ['type' => 'link', 'href' => $span['href'], 'content' => $this->validateInline($span['content'] ?? [], false)];
            } else {
                $this->invalid('Unsupported inline content.');
            }
        }

        return $result;
    }

    private function validateTable(array $content): array
    {
        if (($content['type'] ?? null) !== 'tableContent' || ! is_array($content['rows'] ?? null) || count($content['rows']) > 200) {
            $this->invalid('Invalid table.');
        }
        if (array_diff(array_keys($content), ['type', 'rows', 'headerRows', 'headerCols', 'columnWidths'])) {
            $this->invalid('Unsupported table property.');
        }
        $rows = [];
        foreach ($content['rows'] as $row) {
            if (! is_array($row['cells'] ?? null) || count($row['cells']) > 20) {
                $this->invalid('A table supports at most twenty columns.');
            }
            $cells = [];
            foreach ($row['cells'] as $cell) {
                if (isset($cell['type']) && $cell['type'] === 'tableCell') {
                    $props = $cell['props'] ?? [];
                    foreach ($props as $key => $value) {
                        if (! in_array($key, ['textColor', 'backgroundColor', 'textAlignment', 'colspan', 'rowspan'], true)) {
                            $this->invalid('Unsupported table cell property.');
                        }
                        if (in_array($key, ['colspan', 'rowspan'], true) && (! is_int($value) || $value < 1 || $value > 20)) {
                            $this->invalid('Invalid table span.');
                        }
                        if ($key === 'textAlignment' && ! in_array($value, ['left', 'center', 'right', 'justify'], true)) {
                            $this->invalid('Invalid alignment.');
                        }
                        if (in_array($key, ['textColor', 'backgroundColor'], true) && (! is_string($value) || ! preg_match('/^(default|gray|brown|red|orange|yellow|green|blue|purple|pink|#[0-9a-f]{6})$/iD', $value))) {
                            $this->invalid('Invalid cell color.');
                        }
                    }
                    $cells[] = ['type' => 'tableCell', 'props' => $props, 'content' => $this->validateInline($cell['content'] ?? [])];
                } else {
                    $cells[] = $this->validateInline($cell);
                }
            }
            $rows[] = ['cells' => $cells];
        }
        $result = ['type' => 'tableContent', 'rows' => $rows];
        if (isset($content['headerRows'])) {
            if (! is_int($content['headerRows']) || $content['headerRows'] < 0 || $content['headerRows'] > count($rows)) {
                $this->invalid('Invalid table header count.');
            }
            $result['headerRows'] = $content['headerRows'];
        }
        if (isset($content['headerCols'])) {
            if (! is_int($content['headerCols']) || $content['headerCols'] < 0 || $content['headerCols'] > 20) {
                $this->invalid('Invalid table header columns.');
            }
            $result['headerCols'] = $content['headerCols'];
        }
        if (isset($content['columnWidths'])) {
            if (! is_array($content['columnWidths']) || count($content['columnWidths']) > 20) {
                $this->invalid('Invalid table widths.');
            }
            foreach ($content['columnWidths'] as $width) {
                if ($width !== null && (! is_numeric($width) || $width < 20 || $width > 2000)) {
                    $this->invalid('Invalid column width.');
                }
            }
            $result['columnWidths'] = $content['columnWidths'];
        }

        return $result;
    }

    public function safeUrl(string $url): bool
    {
        if (strlen($url) > 3000 || preg_match('/[\x00-\x20\\\\<>]/', $url)) {
            return false;
        }
        if (preg_match('~^/(?!/)~', $url) || preg_match('/^#[a-zA-Z0-9_-]+$/', $url)) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['https', 'http', 'mailto', 'tel'], true) && ! str_contains($url, '"') && ! str_contains($url, "'");
    }

    /** A same-site theme asset path; dot segments would let the browser resolve it to another application route. */
    public function themeAssetUrl(string $url): bool
    {
        return preg_match('~^/theme-assets/[a-zA-Z0-9_-]+/[a-zA-Z0-9_./-]+$~D', $url) === 1 && array_intersect(array_slice(explode('/', rawurldecode($url)), 1), ['', '.', '..']) === [];
    }

    public function html(array $blocks, array $fields = [], bool $forExport = false, array $images = []): string
    {
        $blocks = $this->validate($blocks);

        return $this->renderBlocks($blocks, $fields, $forExport, $images);
    }

    private function renderBlocks(array $blocks, array $fields, bool $forExport, array $images = []): string
    {
        $html = '';
        $list = null;
        foreach ($blocks as $block) {
            $type = $block['type'];
            $props = $block['props'];
            $currentList = match ($type) {
                'bulletListItem', 'checkListItem' => 'ul', 'numberedListItem' => 'ol', default => null
            };
            if ($list && $list !== $currentList) {
                $html .= '</'.$list.'>';
                $list = null;
            }
            if ($currentList && $list !== $currentList) {
                $list = $currentList;
                $html .= '<'.$list.($list === 'ol' && isset($props['start']) ? ' start="'.(int) $props['start'].'"' : '').'>';
            }
            $inline = $type === 'table' ? '' : $this->inlineHtml($block['content'] ?? []);
            $children = $this->renderBlocks($block['children'], $fields, $forExport, $images);
            $alignment = $this->styles($props);
            switch ($type) {
                case 'heading': $level = $props['level'] ?? 2;
                    $html .= "<h{$level}{$alignment}>{$inline}</h{$level}>{$children}";
                    break;
                case 'bulletListItem': case 'numberedListItem': $html .= '<li'.$alignment.'>'.$inline.$children.'</li>';
                    break;
                case 'checkListItem': $html .= '<li'.$alignment.'>'.(! empty($props['checked']) ? '[x] ' : '[ ] ').$inline.$children.'</li>';
                    break;
                case 'quote': $html .= '<blockquote'.$alignment.'>'.$inline.$children.'</blockquote>';
                    break;
                case 'table':
                    $html .= '<table>';
                    $rowIndex = 0;
                    foreach ($block['content']['rows'] as $row) {
                        $html .= '<tr>';
                        $headerRow = $rowIndex++ < ($block['content']['headerRows'] ?? 0);
                        $columnIndex = 0;
                        foreach ($row['cells'] as $cell) {
                            $tag = $headerRow || $columnIndex++ < ($block['content']['headerCols'] ?? 0) ? 'th' : 'td';
                            $attrs = '';
                            foreach (['colspan', 'rowspan'] as $prop) {
                                if (isset($cell['props'][$prop])) {
                                    $attrs .= ' '.$prop.'="'.(int) $cell['props'][$prop].'"';
                                }
                            }
                            $html .= '<'.$tag.$attrs.$this->styles($cell['props'] ?? []).'>'.$this->inlineHtml($cell['content'] ?? $cell).'</'.$tag.'>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</table>'.$children;
                    break;
                case 'image':
                    $url = $props['url'] ?? '';
                    // PDF engines must never resolve external URLs or authenticated application routes.
                    if ($forExport && isset($images[$props['fileId'] ?? ''])) {
                        $html .= '<p><img src="'.e($images[$props['fileId']]).'" width="480" alt="'.e($props['caption'] ?? '').'"></p>';
                    } elseif ($url && ! $forExport && $this->themeAssetUrl($url)) {
                        $html .= '<figure><img src="'.e($url).'" alt="'.e($props['caption'] ?? $props['name'] ?? '').'" loading="lazy" width="'.(int) ($props['previewWidth'] ?? 720).'" height="480"><figcaption>'.e($props['caption'] ?? '').'</figcaption></figure>';
                    } else {
                        $html .= '<p>[Image: '.e($props['caption'] ?? $props['name'] ?? 'Attachment').']</p>';
                    }
                    $html .= $children;
                    break;
                case 'file': $html .= '<p>Attachment: '.e($props['name'] ?? 'File').($forExport || empty($props['url']) ? '' : ' <a href="'.e($props['url']).'" rel="noopener noreferrer">Open attachment</a>').'</p>'.$children;
                    break;
                case 'citation': $html .= '<aside class="citation"><strong>'.e($props['source'] ?? 'Source').'</strong> '.e($props['pinpoint'] ?? '').' '.$inline.(! empty($props['url']) ? ' <a href="'.e($props['url']).'" rel="noopener noreferrer">Source</a>' : '').'</aside>'.$children;
                    break;
                case 'mergeField': $html .= '<span class="merge-field">'.e($fields[$props['field']] ?? $props['fallback'] ?? '{{'.$props['field'].'}}').'</span>'.$children;
                    break;
                case 'question': $html .= '<aside class="question"><strong>'.(! empty($props['resolved']) ? 'Resolved: ' : 'Open question: ').'</strong>'.$inline.'</aside>'.$children;
                    break;
                case 'pageBreak': $html .= '<div class="page-break" style="page-break-after:always"></div>'.$children;
                    break;
                default: $html .= '<p'.$alignment.'>'.$inline.'</p>'.$children;
            }
        }
        if ($list) {
            $html .= '</'.$list.'>';
        }

        return $html;
    }

    public function inlineHtml(array $content): string
    {
        $html = '';
        foreach ($content as $span) {
            if ($span['type'] === 'link') {
                $html .= '<a href="'.e($span['href']).'" rel="noopener noreferrer">'.$this->inlineHtml($span['content']).'</a>';

                continue;
            }
            $text = nl2br(e($span['text']));
            foreach (['bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'code' => 'code'] as $style => $tag) {
                if (! empty($span['styles'][$style])) {
                    $text = '<'.$tag.'>'.$text.'</'.$tag.'>';
                }
            }
            $color = $this->styles($span['styles'] ?? []);
            $html .= $color ? '<span'.$color.'>'.$text.'</span>' : $text;
        }

        return $html;
    }

    private function styles(array $props): string
    {
        $styles = [];
        if (isset($props['textAlignment'])) {
            $styles[] = 'text-align:'.$props['textAlignment'];
        }
        foreach (['textColor' => 'color', 'backgroundColor' => 'background-color'] as $key => $property) {
            if (isset($props[$key]) && $props[$key] !== 'default') {
                $styles[] = $property.':'.$props[$key];
            }
        }

        return $styles ? ' style="'.e(implode(';', $styles)).'"' : '';
    }

    public function plainText(array $blocks): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</h1>', '</h2>', '</h3>', '</tr>', '</aside>'], "\n", $this->html($blocks, [], true))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['blocks' => $message]);
    }
}
