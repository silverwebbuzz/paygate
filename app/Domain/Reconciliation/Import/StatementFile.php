<?php

namespace App\Domain\Reconciliation\Import;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Throwable;

/**
 * Reads the first sheet of a bank statement file into rows of raw cell
 * values. The type is detected from the content, not the file name: many
 * Indian banks' ".xls" downloads are really HTML tables.
 *
 * CSV / HTML cells stay text (a long UTR must never become a float);
 * Excel cells keep their stored type (dates arrive as serial numbers).
 */
final class StatementFile
{
    private const READERS = ['Xlsx', 'Xls', 'Csv', 'Html'];

    /**
     * @return list<list<mixed>>
     */
    public static function rows(string $path, int $maxRows): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path, self::READERS);

            if ($reader instanceof BaseReader) {
                $reader->setValueBinder(new StringValueBinder);
            }

            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
                $reader->setDelimiter(self::delimiter($path));
            }

            $sheet = $reader->load($path, IReader::READ_DATA_ONLY | IReader::IGNORE_ROWS_WITH_NO_CELLS)->getSheet(0);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('This file can’t be read. Upload the statement as CSV or Excel (.xls / .xlsx).')]);
        }

        $lastRow = min($sheet->getHighestDataRow(), $maxRows);

        if ($lastRow < 1) {
            return [];
        }

        /** @var list<list<mixed>> $rows */
        $rows = array_values(array_map('array_values', $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().$lastRow, null, true, false, false)));

        return $rows;
    }

    /**
     * The CSV separator: whichever of , ; tab | appears most in the first
     * lines (the library's own guess is fooled by headers like "Chq./Ref.No.").
     */
    private static function delimiter(string $path): string
    {
        $sample = (string) file_get_contents($path, false, null, 0, 8192);
        $counts = [];

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($sample, $candidate);
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
