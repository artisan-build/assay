<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\DashboardTable;
use ArtisanBuild\BuiltForCloud\CsvFieldSanitizer;
use RuntimeException;

final class DashboardCsvSerializer
{
    public function serialize(DashboardTable $table): string
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the CSV buffer.');
        }

        if (fputcsv($stream, $table->headers, escape: '', eol: "\r\n") === false) {
            throw new RuntimeException('Unable to write CSV headers.');
        }

        foreach ($table->rows as $row) {
            $cells = [];

            foreach ($table->headers as $header) {
                $value = $row[$header] ?? '';
                $cells[] = in_array($header, $table->userControlledColumns, true)
                    ? CsvFieldSanitizer::sanitize($value)
                    : $value;
            }

            if (fputcsv($stream, $cells, escape: '', eol: "\r\n") === false) {
                throw new RuntimeException('Unable to write a CSV row.');
            }
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new RuntimeException('Unable to read the CSV buffer.');
        }

        return $csv;
    }
}
