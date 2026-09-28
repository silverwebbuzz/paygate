<?php

namespace App\Domain\Reporting;

use App\Domain\Reporting\Reports\Report;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Writes a report to a CSV or Excel file, streaming row by row (OpenSpout
 * for Excel), so large periods don't need the whole report in memory.
 * Money is written in rupees with two decimals, dates in business time,
 * and text never starts a spreadsheet formula.
 */
class ReportWriter
{
    /**
     * @param  array<string, string|null>  $filters
     * @return int rows written
     */
    public function write(Report $report, Scope $scope, Period $period, array $filters, string $format, string $path): int
    {
        $columns = $report->columns($scope);
        $header = array_column($columns, 'label');
        $count = 0;

        if ($format === 'xlsx') {
            $writer = new XlsxWriter;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues($header));

            foreach ($report->rows($scope, $period, $filters) as $row) {
                $writer->addRow(Row::fromValues(array_map(fn (array $column) => $this->cell($column['type'], $row[$column['key']] ?? null, true), $columns)));
                $count++;
            }

            $writer->close();

            return $count;
        }

        $out = fopen($path, 'w');

        if ($out === false) {
            throw new RuntimeException('Could not write the export file.');
        }

        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows ₹ and names correctly
        fputcsv($out, $header);

        foreach ($report->rows($scope, $period, $filters) as $row) {
            fputcsv($out, array_map(fn (array $column) => $this->cell($column['type'], $row[$column['key']] ?? null, false), $columns));
            $count++;
        }

        fclose($out);

        return $count;
    }

    private function cell(string $type, mixed $value, bool $typed): string|int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'money', 'signed' => $typed ? round((int) $value / 100, 2) : number_format((int) $value / 100, 2, '.', ''),
            'count' => (int) $value,
            'percent' => (float) $value,
            'date' => CarbonImmutable::parse((string) $value)->setTimezone((string) config('app.business_timezone'))->format('Y-m-d H:i:s'),
            default => $this->safeText((string) $value),
        };
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
