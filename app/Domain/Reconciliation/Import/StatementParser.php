<?php

namespace App\Domain\Reconciliation\Import;

use App\Domain\Transaction\Actions\SubmitPayinProof;
use App\Domain\Transaction\Models\Transaction;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Turns the rows of a statement file into statement lines, using a
 * ColumnMapping. Also guesses the header row and the mapping for a new
 * layout, so the person uploading only has to check it.
 *
 * Rows without any credit or debit (opening balance, totals, footers) are
 * skipped; rows that can't be read are reported with their row number.
 */
final class StatementParser
{
    /**
     * Header row (1-based) and a mapping guessed from the header names.
     *
     * @param  list<list<mixed>>  $rows
     * @return array<string, int|string|null>
     */
    public static function guess(array $rows): array
    {
        $headerRow = 1;

        foreach (array_slice($rows, 0, 40) as $index => $row) {
            $cells = array_map(fn ($cell) => mb_strtolower(self::text($cell)), $row);
            $joined = implode(' | ', $cells);

            if (count(array_filter($cells)) >= 3 && str_contains($joined, 'date') && preg_match('/credit|deposit|debit|withdraw|amount/', $joined) === 1) {
                $headerRow = $index + 1;
                break;
            }
        }

        $headers = array_map(fn ($cell) => mb_strtolower(self::text($cell)), $rows[$headerRow - 1] ?? []);
        $find = function (string $pattern, ?string $not = null) use ($headers): ?int {
            foreach ($headers as $index => $header) {
                if ($header !== '' && preg_match($pattern, $header) === 1 && ($not === null || preg_match($not, $header) !== 1)) {
                    return $index;
                }
            }

            return null;
        };

        $type = $find('/cr\s*\/\s*dr|dr\s*\/\s*cr|^type$|debit\s*\/\s*credit|credit\s*\/\s*debit/');
        $credit = $find('/credit|deposit|^cr\.?$/', '/debit|withdraw|\//');
        $debit = $find('/debit|withdraw|^dr\.?$/', '/credit|deposit|\//');
        $mapping = [
            'header_row' => $headerRow,
            'date' => $find('/value\s*date/') ?? $find('/date/'),
            'date_format' => ColumnMapping::DATE_FORMATS[0],
            'description' => $find('/narration|description|particular|remark|details/'),
            'utr' => $find('/utr|ref(erence)?|chq|cheque/', '/date/'),
            'credit' => $credit,
            'debit' => $debit,
            'amount' => $credit === null && $debit === null ? $find('/amount|amt/') : null,
            'type' => $credit === null && $debit === null ? $type : null,
            'balance' => $find('/balance/'),
        ];

        if ($mapping['date'] !== null) {
            $mapping['date_format'] = self::guessDateFormat(array_column(array_slice($rows, $headerRow, 30), (int) $mapping['date']));
        }

        return $mapping;
    }

    /**
     * The header row's cell texts (for the mapping screen).
     *
     * @param  list<list<mixed>>  $rows
     * @return list<string>
     */
    public static function headers(array $rows, int $headerRow): array
    {
        return array_map(fn ($cell) => self::text($cell), $rows[$headerRow - 1] ?? []);
    }

    /**
     * Identifies a bank's layout: the same header row finds the same saved
     * mapping next time.
     *
     * @param  list<string>  $headers
     */
    public static function signature(array $headers): string
    {
        $normalised = array_map(fn (string $header) => mb_strtolower(preg_replace('/\s+/', ' ', trim($header)) ?? ''), $headers);

        while ($normalised !== [] && end($normalised) === '') {
            array_pop($normalised);
        }

        return hash('sha256', implode('|', $normalised));
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return array{lines: list<array{row: int, date: string, direction: string, amount: int, utr_normalized: ?string, description: ?string, balance: ?string}>, errors: list<array{row: int, message: string}>}
     */
    public static function parse(array $rows, ColumnMapping $mapping): array
    {
        $lines = [];
        $errors = [];

        foreach (array_slice($rows, $mapping->headerRow, null, true) as $index => $row) {
            $rowNo = $index + 1;
            $cell = fn (?int $column) => $column === null ? '' : ($row[$column] ?? '');

            if (array_filter($row, fn ($value) => self::text($value) !== '') === []) {
                continue;
            }

            try {
                [$direction, $amount] = self::direction($mapping, $cell);
            } catch (InvalidArgumentException $exception) {
                $errors[] = ['row' => $rowNo, 'message' => $exception->getMessage()];

                continue;
            }

            if ($direction === null) {
                continue; // opening balance, totals…
            }

            $date = self::parseDate($cell($mapping->date), $mapping->dateFormat);

            if ($date === null) {
                $errors[] = ['row' => $rowNo, 'message' => __('Date “:value” is not in the format :format.', ['value' => self::text($cell($mapping->date)), 'format' => $mapping->dateFormat])];

                continue;
            }

            $description = self::text($cell($mapping->description));
            $utrText = self::text($cell($mapping->utr));
            $utr = self::utrFrom($utrText) ?? self::extractUtr($description);

            $lines[] = [
                'row' => $rowNo,
                'date' => $date,
                'direction' => $direction,
                'amount' => $amount,
                'utr_normalized' => $utr,
                'description' => $description === '' ? null : mb_substr($description, 0, 500),
                'balance' => $mapping->balance === null ? null : self::text($cell($mapping->balance)),
            ];
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    /**
     * A UTR in free text (a narration such as "UPI/626812820491/Payment
     * from…" or "NEFT-HDFCN52026092812345678-…"): first a 12-digit UPI /
     * IMPS reference, then a NEFT / RTGS UTR (4-letter bank code + digits),
     * then any 13–22 digit number.
     */
    public static function extractUtr(string $text): ?string
    {
        $tokens = preg_split('/[^A-Z0-9]+/', mb_strtoupper($text)) ?: [];

        foreach (['/^\d{12}$/', '/^[A-Z]{4}(?=(?:[A-Z]*\d){8})[A-Z0-9]{12,18}$/', '/^\d{13,22}$/'] as $pattern) {
            foreach ($tokens as $token) {
                if (preg_match($pattern, $token) === 1) {
                    return $token;
                }
            }
        }

        return null;
    }

    /**
     * A date cell as Y-m-d: an Excel serial number, or text in the given
     * format (a time after the date is ignored).
     */
    public static function parseDate(mixed $value, string $format): ?string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)) === 1)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        $text = preg_replace('/[\sT]+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?\s*([AaPp][Mm])?\s*$/', '', self::text($value)) ?? '';
        $date = DateTimeImmutable::createFromFormat('!'.$format, $text);
        $problems = DateTimeImmutable::getLastErrors();

        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        $year = (int) $date->format('Y');

        return $year >= 2000 && $year <= 2100 ? $date->format('Y-m-d') : null;
    }

    /**
     * An amount cell in paise, signed; null when empty. "1,23,456.50",
     * "₹ 500", "500.00 Cr", "(500.00)" and "-500" are understood.
     */
    public static function parseAmount(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round($value * 100);
        }

        $text = mb_strtoupper(self::text($value));
        $negative = str_starts_with($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')')) || str_ends_with($text, 'DR');
        $digits = str_replace([',', ' ', '₹', 'INR', 'RS.', 'RS', 'CR', 'DR', '(', ')', '-', '+'], '', $text);

        if ($digits === '') {
            return null;
        }

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $digits) !== 1) {
            throw new InvalidArgumentException(__('Amount “:value” can’t be read.', ['value' => self::text($value)]));
        }

        [$whole, $fraction] = array_pad(explode('.', $digits), 2, '');
        $paise = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$paise : $paise;
    }

    /**
     * A cell as trimmed text; whole numbers without ".0" or exponent (a UTR
     * stored as a number in Excel).
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_float($value) && floor($value) === $value && abs($value) < 1e22 => sprintf('%.0f', $value),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    /**
     * Credit or debit and the positive amount, from the mapped columns;
     * [null, 0] when the row has no amount.
     *
     * @param  callable(?int): mixed  $cell
     * @return array{0: ?string, 1: int}
     */
    private static function direction(ColumnMapping $mapping, callable $cell): array
    {
        if ($mapping->amount !== null) {
            $amount = self::parseAmount($cell($mapping->amount));

            if ($amount === null || $amount === 0) {
                return [null, 0];
            }

            $type = mb_strtoupper(self::text($cell($mapping->type)));
            $direction = match (true) {
                $type !== '' && str_starts_with($type, 'C') => 'credit',
                $type !== '' && str_starts_with($type, 'D') => 'debit',
                default => $amount < 0 ? 'debit' : 'credit',
            };

            return [$direction, abs($amount)];
        }

        $credit = self::parseAmount($cell($mapping->credit));
        $debit = self::parseAmount($cell($mapping->debit));
        $credit = $credit === 0 ? null : $credit;
        $debit = $debit === 0 ? null : $debit;

        if ($credit !== null && $debit !== null) {
            throw new InvalidArgumentException(__('The row has both a credit and a debit.'));
        }

        return match (true) {
            $credit !== null => ['credit', abs($credit)],
            $debit !== null => ['debit', abs($debit)],
            default => [null, 0],
        };
    }

    /**
     * The UTR column's value if it is a UTR (not a cheque number such as
     * "0" or a whole narration), else one found inside it.
     */
    private static function utrFrom(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        $normalised = Transaction::normaliseUtr($text);

        // Some banks pad the reference to 16 digits: 0000626812820491 is
        // the 12-digit UPI / IMPS reference 626812820491.
        if (ctype_digit($normalised) && strlen($normalised) > 12 && str_starts_with($normalised, '0')) {
            $normalised = str_pad(ltrim($normalised, '0'), 12, '0', STR_PAD_LEFT);
        }

        if (preg_match(SubmitPayinProof::UTR_PATTERN, $normalised) === 1 && preg_match('/\d{6}/', $normalised) === 1 && trim($normalised, '0') !== '') {
            return $normalised;
        }

        return self::extractUtr($text);
    }

    /**
     * The date format that reads the most samples (the first on a tie).
     *
     * @param  array<int, mixed>  $samples
     */
    private static function guessDateFormat(array $samples): string
    {
        $samples = array_filter($samples, fn ($value) => self::text($value) !== '');
        $best = ColumnMapping::DATE_FORMATS[0];
        $bestCount = 0;

        foreach (ColumnMapping::DATE_FORMATS as $format) {
            $count = count(array_filter($samples, fn ($value) => self::parseDate($value, $format) !== null));

            if ($count > $bestCount) {
                [$best, $bestCount] = [$format, $count];
            }
        }

        return $best;
    }
}
