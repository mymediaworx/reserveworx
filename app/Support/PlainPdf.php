<?php

namespace App\Support;

/**
 * A small text-only PDF. Helvetica, one column, no images.
 * Characters that Windows-1252 cannot hold are dropped.
 */
final class PlainPdf
{
    /**
     * @param  list<string>  $lines
     */
    public function render(array $lines): string
    {
        $pages = $this->pages($lines);
        $objects = [];
        $pageIds = [];
        $next = 4;

        foreach ($pages as $pageLines) {
            $pageIds[] = $next;
            $stream = $this->stream($pageLines);
            $objects[$next] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.($next + 1).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
            $objects[$next + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
            $next += 2;
        }

        $kids = implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Count '.count($pageIds).' /Kids [ '.$kids.' ] >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 $size\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $pdf."trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }

    /**
     * @param  list<string>  $lines
     * @return list<list<string>>
     */
    private function pages(array $lines): array
    {
        $wrapped = [];

        foreach ($lines as $line) {
            foreach ($this->wrap($line) as $part) {
                $wrapped[] = $part;
            }
        }

        if ($wrapped === []) {
            $wrapped[] = '';
        }

        return array_chunk($wrapped, 46);
    }

    /**
     * @return list<string>
     */
    private function wrap(string $line): array
    {
        $text = $this->latin($line);

        if ($text === '') {
            return [''];
        }

        $lines = [];

        while (strlen($text) > 90) {
            $window = substr($text, 0, 90);
            $break = strrpos($window, ' ');
            $at = $break === false || $break === 0 ? 90 : $break;
            $lines[] = rtrim(substr($text, 0, $at));
            $text = ltrim(substr($text, $at));
        }

        $lines[] = $text;

        return $lines;
    }

    private function latin(string $text): string
    {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        if (! is_string($converted)) {
            return '';
        }

        return str_replace(["\r", "\n", "\t"], ' ', $converted);
    }

    /**
     * @param  list<string>  $lines
     */
    private function stream(array $lines): string
    {
        $commands = ['BT', '/F1 11 Tf', '50 760 Td'];
        $first = true;

        foreach ($lines as $line) {
            if (! $first) {
                $commands[] = '0 -14 Td';
            }

            $first = false;
            $commands[] = '('.$this->escape($line).') Tj';
        }

        $commands[] = 'ET';

        return implode("\n", $commands);
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
