<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';

class Exporter
{
    /** @var string */
    private $exportDir;

    public function __construct(string $dir = null)
    {
        $this->exportDir = $dir ?: (UPLOADS_DIR . '/exports');
        if (!is_dir($this->exportDir)) @mkdir($this->exportDir, 0775, true);
    }

    public function exportTXT(int $novelId): ?string
    {
        $novel = find_novel($novelId); if (!$novel) return null;
        $chapters = list_chapters($novelId, 'published');
        $lines = [];
        $lines[] = $novel['title'] . "\n";
        $lines[] = '作者：' . get_user_display_name((int)$novel['author_id']) . "\n";
        $lines[] = str_repeat('=', 20) . "\n\n";
        foreach ($chapters as $c) {
            $lines[] = '第' . (int)$c['id'] . '章 ' . ($c['title'] ?? '') . "\n\n";
            $lines[] = (string)($c['content'] ?? '') . "\n\n";
        }
        $content = implode('', $lines);
        $file = $this->exportDir . '/' . $this->safeFileName($novel['title']) . '-' . date('Ymd_His') . '.txt';
        file_put_contents($file, $content);
        @chmod($file, 0664);
        return $file;
    }

    public function exportEPUB(int $novelId): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null; // ZipArchive not available
        }
        $novel = find_novel($novelId); if (!$novel) return null;
        $chapters = list_chapters($novelId, 'published');
        $file = $this->exportDir . '/' . $this->safeFileName($novel['title']) . '-' . date('Ymd_His') . '.epub';
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
        // EPUB structure
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0" encoding="UTF-8"?>\n<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">\n  <rootfiles>\n    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>\n  </rootfiles>\n</container>');
        $manifestItems = [];
        $spineItems = [];
        $index = 1;
        foreach ($chapters as $c) {
            $html = '<html xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8" /><title>'.htmlspecialchars($c['title'] ?? '').'</title></head><body>';
            $html .= '<h1>'.htmlspecialchars($c['title'] ?? '').'</h1>';
            $html .= '<pre style="white-space: pre-wrap; font-family: inherit">'.htmlspecialchars((string)($c['content'] ?? '')).'</pre>';
            $html .= '</body></html>';
            $name = 'OEBPS/ch'.$index.'.xhtml';
            $zip->addFromString($name, $html);
            $manifestItems[] = '<item id="ch'.$index.'" href="ch'.$index.'.xhtml" media-type="application/xhtml+xml" />';
            $spineItems[] = '<itemref idref="ch'.$index.'" />';
            $index++;
        }
        $metadata = '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
                  . '<dc:title>'.htmlspecialchars($novel['title']).'</dc:title>'
                  . '<dc:creator>'.htmlspecialchars(get_user_display_name((int)$novel['author_id'])).'</dc:creator>'
                  . '<dc:language>zh-CN</dc:language>'
                  . '<meta property="dcterms:modified">'.date('c').'</meta>'
                  . '</metadata>';
        $contentOpf = '<?xml version="1.0" encoding="UTF-8"?>\n'
                    . '<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id" version="3.0">'
                    . $metadata
                    . '<manifest>' . implode('', $manifestItems) . '</manifest>'
                    . '<spine>' . implode('', $spineItems) . '</spine>'
                    . '</package>';
        $zip->addFromString('OEBPS/content.opf', $contentOpf);
        $zip->close();
        @chmod($file, 0664);
        return $file;
    }

    public function exportPDF(int $novelId): ?string
    {
        $novel = find_novel($novelId); if (!$novel) return null;
        $chapters = list_chapters($novelId, 'published');
        $text = $novel['title'] . "\n作者：" . get_user_display_name((int)$novel['author_id']) . "\n\n";
        foreach ($chapters as $c) {
            $text .= '第' . (int)$c['id'] . '章 ' . ($c['title'] ?? '') . "\n\n" . (string)($c['content'] ?? '') . "\n\n";
        }
        $file = $this->exportDir . '/' . $this->safeFileName($novel['title']) . '-' . date('Ymd_His') . '.pdf';
        $ok = $this->writeMinimalPdf($text, $file);
        return $ok ? $file : null;
    }

    private function safeFileName(string $name): string
    {
        $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
        $name = trim($name);
        if ($name === '') $name = 'novel';
        return $name;
    }

    private function writeMinimalPdf(string $text, string $file): bool
    {
        // Extremely simple PDF writer (single page, Helvetica, text flow)
        // This is not a full-featured PDF but works for basic text export.
        $lines = explode("\n", str_replace(["\r\n","\r"], "\n", $text));
        $contentStream = "BT\n/F1 12 Tf\n 72 770 Td\n";
        $y = 770; $pageHeight = 842; // A4 in points
        foreach ($lines as $line) {
            $esc = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $line);
            $contentStream .= "( $esc ) Tj\n";
            $y -= 14;
            $contentStream .= "0 -14 Td\n";
            if ($y < 72) { // simplistic: ignore overflow
                break;
            }
        }
        $contentStream .= "ET\n";
        $objects = [];
        $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n";
        $objects[] = "4 0 obj << /Length " . strlen($contentStream) . " >> stream\n" . $contentStream . "endstream endobj\n";
        $objects[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
        // Build xref
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) { $offsets[] = strlen($pdf); $pdf .= $obj; }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects)+1) . "\n";
        $pdf .= sprintf("%010d %05d f \n", 0, 65535);
        for ($i=1; $i<=count($objects); $i++) {
            $pdf .= sprintf("%010d %05d n \n", $offsets[$i], 0);
        }
        $pdf .= "trailer << /Size " . (count($objects)+1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return (bool)file_put_contents($file, $pdf);
    }
}
