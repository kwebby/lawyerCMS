<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Unit\Publishing;

use App\Domain\Publishing\BlockDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlockDocumentTest extends TestCase
{
    public function test_supported_blocks_keep_semantic_structure_and_escape_html(): void
    {
        $renderer = new BlockDocument;
        $blocks = [
            ['type' => 'heading', 'props' => ['level' => 2], 'content' => 'A <script>heading</script>'],
            ['type' => 'bulletListItem', 'content' => 'First', 'children' => [['type' => 'numberedListItem', 'content' => 'Nested']]],
            ['type' => 'bulletListItem', 'content' => 'Second'],
            ['type' => 'table', 'content' => ['type' => 'tableContent', 'headerRows' => 1, 'rows' => [['cells' => [[['type' => 'text', 'text' => 'Name', 'styles' => ['bold' => true]]]]], ['cells' => [['type' => 'tableCell', 'props' => ['colspan' => 1], 'content' => 'Example']]]]]],
            ['type' => 'citation', 'props' => ['source' => 'Source', 'url' => 'https://example.com'], 'content' => 'Paragraph 1'],
            ['type' => 'mergeField', 'props' => ['field' => 'client.name']],
            ['type' => 'question', 'props' => ['resolved' => false], 'content' => 'Confirm date'],
            ['type' => 'pageBreak'],
        ];
        $validated = $renderer->validate($blocks);
        $this->assertSame($validated, $renderer->validate($validated));
        $html = $renderer->html($validated, ['client.name' => 'Alex & Co']);
        $this->assertStringContainsString('<ul><li>First<ol><li>Nested</li></ol></li><li>Second</li></ul>', $html);
        $this->assertStringContainsString('<th><strong>Name</strong></th>', $html);
        $this->assertStringContainsString('Alex &amp; Co', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_unsafe_link_schemes_unknown_blocks_and_props_are_rejected(): void
    {
        $cases = [
            [['type' => 'paragraph', 'content' => [['type' => 'link', 'href' => 'javascript:alert(1)', 'content' => 'Click']]]],
            [['type' => 'paragraph', 'content' => [['type' => 'link', 'href' => '//evil.example', 'content' => 'Click']]]],
            [['type' => 'iframe', 'content' => 'bad']],
            [['type' => 'paragraph', 'props' => ['onload' => 'alert(1)'], 'content' => 'bad']],
            [['type' => 'image', 'props' => ['url' => 'file:///etc/passwd']]],
        ];
        foreach ($cases as $blocks) {
            try {
                (new BlockDocument)->validate($blocks);
                $this->fail('Unsafe content accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('blocks', $error->errors());
            }
        }
    }

    public function test_export_never_fetches_remote_images(): void
    {
        $html = (new BlockDocument)->html([['type' => 'image', 'props' => ['url' => 'https://example.com/image.png', 'caption' => 'Reference']]], [], true);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Reference', $html);
    }
}
