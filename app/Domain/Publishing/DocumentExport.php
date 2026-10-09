<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Domain\Publishing;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\PrivateFiles;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html;

final class DocumentExport
{
    public function __construct(private BlockDocument $renderer, private RecordStore $store, private PrivateFiles $files, private Access $access) {}

    public function generate(array $record, array $blocks, string $format, $user): string
    {
        $images = [];
        $this->collectImages($blocks, $images, $user);
        $html = $this->renderer->html($blocks, [], true, $images);
        $directory = rtrim(config('crm.private_path'), '/').'/export_tmp/'.bin2hex(random_bytes(16));
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot prepare private export storage.');
        }
        try {
            if ($format === 'pdf') {
                $pdf = new Mpdf(['tempDir' => $directory, 'mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
                $pdf->SetTitle($record['title']);
                $pdf->SetAuthor($record['author_name'] ?? '');
                $pdf->WriteHTML('<style>body{font-family:dejavusans;font-size:11pt;color:#222}h1{font-size:22pt}h2{font-size:17pt}p,li{line-height:1.55}table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:6px}.citation,.question{border-left:2px solid #777;padding:10px}img{max-width:100%}</style><h1>'.e($record['title']).'</h1>'.$html);

                return $pdf->Output('', Destination::STRING_RETURN);
            }
            abort_unless($format === 'docx', 404);
            $previousTemp = Settings::getTempDir();
            Settings::setTempDir($directory);
            try {
                $word = new PhpWord;
                $word->setDefaultFontName('Calibri');
                $word->setDefaultFontSize(11);
                $word->getDocInfo()->setTitle($record['title']);
                $section = $word->addSection();
                $section->addTitle($record['title'], 1);
                $html = str_replace(['<aside', '</aside>', '<blockquote>', '</blockquote>', '<br>', '<img '], ['<div', '</div>', '<p>', '</p>', '<br/>', '<img '], $html);
                $html = preg_replace('/<img([^>]+)(?<!\/)\>/', '<img$1/>', $html);
                Html::addHtml($section, $html, false, false);
                $target = $directory.'/document.docx';
                IOFactory::createWriter($word, 'Word2007')->save($target);

                return file_get_contents($target);
            } finally {
                Settings::setTempDir($previousTemp);
            }
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    private function collectImages(array $blocks, array &$images, $user): void
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'image' && ! empty($block['props']['fileId'])) {
                $file = $this->store->get('documents', $block['props']['fileId']);
                abort_unless($file && ($file['status'] ?? '') === 'clean' && in_array($file['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true), 422, 'An image attachment is missing or not cleared for use.');
                $this->access->authorize($user, 'documents.read', $file);
                $bytes = $this->files->read($file['path']);
                $size = @getimagesizefromstring($bytes);
                abort_unless($size && $size[0] <= 4096 && $size[1] <= 4096, 422, 'Image dimensions exceed export limits.');
                $images[$file['id']] = 'data:'.$file['mime'].';base64,'.base64_encode($bytes);
            }
            $this->collectImages($block['children'] ?? [], $images, $user);
        }
    }
}
