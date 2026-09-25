<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Minimal, dependency-free PDF 1.4 writer (A4, built-in Helvetica fonts, text, lines,
 * filled rectangles). Enough for statements and receipts on shared hosting without Composer.
 * Coordinates are in points from the TOP-left corner.
 */
final class PdfDocument
{
    public const W = 595.28;
    public const H = 841.89;

    private array $pages = [];
    private string $current = '';

    public function addPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = ' ';
    }

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, string $align = 'left', array $rgb = [0.09, 0.13, 0.17]): void
    {
        $enc = self::encode($text);
        if ($align !== 'left') {
            $w = self::width($text, $size, $bold);
            $x = $align === 'right' ? $x - $w : $x - $w / 2;
        }
        $this->current .= sprintf(
            "BT %.3f %.3f %.3f rg /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, self::H - $y, $enc
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.85, 0.87, 0.9]): void
    {
        $this->current .= sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $rgb[0], $rgb[1], $rgb[2], $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->current .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n", $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h);
    }

    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [0.04, 0.24, 0.39];
        }
        return array_map(fn ($c) => hexdec($c) / 255, str_split($hex, 2));
    }

    /** Approximate Helvetica string width (average glyph widths), good enough for alignment. */
    public static function width(string $text, float $size, bool $bold = false): float
    {
        $w = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $w += match (true) {
                ctype_digit($ch) => 556,
                in_array($ch, ['.', ',', ':', ';', 'i', 'l', 'j', "'", '|', '!'], true) => 278,
                in_array($ch, [' ', 'f', 't', 'r', 'I', '-', '(', ')', '/'], true) => 300,
                in_array($ch, ['m', 'w', 'M', 'W'], true) => 850,
                ctype_upper($ch) => 680,
                default => 530,
            };
        }
        return $w / 1000 * $size * ($bold ? 1.05 : 1.0);
    }

    public static function truncate(string $text, float $maxWidth, float $size): string
    {
        if (self::width($text, $size) <= $maxWidth) {
            return $text;
        }
        while ($text !== '' && self::width($text . '…', $size) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }

    private static function encode(string $text): string
    {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($s === false) {
            $s = preg_replace('/[^\x20-\x7E]/', '?', $text);
        }
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    public function output(string $title = 'Document'): string
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
            $this->current = '';
        }
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Title (' . self::encode($title) . ') /Producer (' . self::encode(bank_name()) . ') /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        $kids = [];
        $n = 6;
        foreach ($this->pages as $content) {
            $stream = gzcompress($content);
            $objects[$n] = '<< /Length ' . strlen($stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream";
            $objects[$n + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::W, self::H, $n);
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}
