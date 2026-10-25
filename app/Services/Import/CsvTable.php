<?php

namespace App\Services\Import;

/**
 * Reads a CSV from disk without loading every row into memory.
 */
final class CsvTable
{
    /**
     * @return array{headers: list<string>, delimiter: string, data_offset: int, row_count: int}
     */
    public function describe(string $absolutePath, int $maxRows, int $maxColumns): array
    {
        $this->assertUtf8($absolutePath);
        $handle = $this->open($absolutePath);

        try {
            $start = $this->skipBom($handle);
            $delimiter = $this->sniffDelimiter($handle, $start);
            $header = $this->readRecord($handle, $delimiter);

            if ($header === null || $this->blank($header)) {
                throw new ImportException('That file has no header row.');
            }

            if (count($header) > $maxColumns) {
                throw new ImportException('That file has too many columns.');
            }

            $headers = [];

            foreach ($header as $index => $cell) {
                $label = $cell;

                if ($index === 0) {
                    $stripped = preg_replace('/^\xEF\xBB\xBF/', '', $label);
                    $label = is_string($stripped) ? $stripped : $label;
                }

                $headers[] = mb_substr(trim($label), 0, 200);
            }

            $dataOffset = $this->position($handle);
            $rowCount = 0;

            while (($record = $this->readRecord($handle, $delimiter)) !== null) {
                if ($this->blank($record)) {
                    continue;
                }

                $rowCount++;

                if ($rowCount > $maxRows) {
                    throw new ImportException('That file has more than '.number_format($maxRows).' rows. Split it and import the parts.');
                }
            }

            return [
                'headers' => $headers,
                'delimiter' => $delimiter,
                'data_offset' => $dataOffset,
                'row_count' => $rowCount,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{rows: list<array{row: int, cells: list<string>}>, byte_offset: int, next_row: int}
     */
    public function read(string $absolutePath, string $delimiter, int $byteOffset, int $nextRow, int $limit): array
    {
        $handle = $this->open($absolutePath);

        try {
            if (fseek($handle, $byteOffset) !== 0) {
                throw new ImportException('The import file could not be read.');
            }

            $rows = [];
            $rowNumber = $nextRow;

            while (count($rows) < $limit) {
                $record = $this->readRecord($handle, $delimiter);

                if ($record === null) {
                    break;
                }

                $current = $rowNumber;
                $rowNumber++;

                if ($this->blank($record)) {
                    continue;
                }

                $rows[] = ['row' => $current, 'cells' => $record];
            }

            return [
                'rows' => $rows,
                'byte_offset' => $this->position($handle),
                'next_row' => $rowNumber,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open(string $absolutePath)
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new ImportException('The import file could not be read.');
        }

        return $handle;
    }

    private function assertUtf8(string $absolutePath): void
    {
        $contents = file_get_contents($absolutePath);

        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new ImportException('Save the file as UTF-8 and try again.');
        }
    }

    /**
     * @param  resource  $handle
     */
    private function skipBom($handle): int
    {
        $prefix = fread($handle, 3);

        if ($prefix === "\xEF\xBB\xBF") {
            return 3;
        }

        rewind($handle);

        return 0;
    }

    /**
     * @param  resource  $handle
     */
    private function sniffDelimiter($handle, int $start): string
    {
        $line = fgets($handle, 8192);
        fseek($handle, $start);

        if (! is_string($line) || $line === '') {
            return ',';
        }

        $stripped = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $line = is_string($stripped) ? $stripped : $line;
        $best = ',';
        $score = substr_count($line, ',');

        foreach ([';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")] as $delimiter => $count) {
            if ($count > $score) {
                $best = $delimiter;
                $score = $count;
            }
        }

        return $best;
    }

    /**
     * @param  resource  $handle
     * @return list<string>|null
     */
    private function readRecord($handle, string $delimiter): ?array
    {
        $record = fgetcsv($handle, null, $delimiter, '"', '\\');

        if ($record === false) {
            return null;
        }

        $cells = [];

        foreach ($record as $cell) {
            $text = is_string($cell) ? $cell : '';
            $cells[] = trim(str_replace("\0", '', $text));
        }

        return $cells;
    }

    /**
     * @param  list<string>  $cells
     */
    private function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  resource  $handle
     */
    private function position($handle): int
    {
        $position = ftell($handle);

        if ($position === false) {
            throw new ImportException('The import file could not be read.');
        }

        return $position;
    }
}
