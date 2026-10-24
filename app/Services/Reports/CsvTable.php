<?php

namespace App\Services\Reports;

use RuntimeException;

/**
 * Writes one CSV table. A null cell is blank, so an empty rate is not a zero.
 */
final class CsvTable
{
    /**
     * @param  list<string>  $header
     * @param  list<list<int|string|null>>  $rows
     */
    public function render(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            throw new RuntimeException('Could not build the report.');
        }

        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (int|string|null $cell): string => $cell === null ? '' : (string) $cell,
                $row,
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }
}
