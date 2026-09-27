<?php

namespace App\Domain\Reconciliation\Import;

use Illuminate\Validation\ValidationException;

/**
 * Which column of a statement file holds what (0-based column indexes).
 * Amounts come either as separate Credit / Debit columns (most Indian bank
 * statements: "Deposit Amt." / "Withdrawal Amt."), or as one Amount column
 * with an optional Cr/Dr column (else the sign decides).
 */
final class ColumnMapping
{
    public const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'd-M-Y', 'd M Y', 'd-M-y', 'd M y', 'd/M/Y', 'Y-m-d', 'Y/m/d', 'm/d/Y'];

    public function __construct(
        public readonly int $headerRow,
        public readonly int $date,
        public readonly string $dateFormat,
        public readonly ?int $description = null,
        public readonly ?int $utr = null,
        public readonly ?int $credit = null,
        public readonly ?int $debit = null,
        public readonly ?int $amount = null,
        public readonly ?int $type = null,
        public readonly ?int $balance = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $column = fn (string $key): ?int => isset($data[$key]) && $data[$key] !== '' && is_numeric($data[$key]) ? (int) $data[$key] : null;

        $mapping = new self(
            headerRow: max(1, (int) ($data['header_row'] ?? 1)),
            date: $column('date') ?? -1,
            dateFormat: in_array($data['date_format'] ?? null, self::DATE_FORMATS, true) ? (string) $data['date_format'] : self::DATE_FORMATS[0],
            description: $column('description'),
            utr: $column('utr'),
            credit: $column('credit'),
            debit: $column('debit'),
            amount: $column('amount'),
            type: $column('type'),
            balance: $column('balance'),
        );

        if ($mapping->date < 0) {
            throw ValidationException::withMessages(['mapping.date' => __('Choose the date column.')]);
        }

        if ($mapping->credit === null && $mapping->debit === null && $mapping->amount === null) {
            throw ValidationException::withMessages(['mapping.credit' => __('Choose the credit / debit columns, or one amount column.')]);
        }

        if ($mapping->utr === null && $mapping->description === null) {
            throw ValidationException::withMessages(['mapping.utr' => __('Choose the UTR column or the description column (the UTR is read from it).')]);
        }

        return $mapping;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'header_row' => $this->headerRow,
            'date' => $this->date,
            'date_format' => $this->dateFormat,
            'description' => $this->description,
            'utr' => $this->utr,
            'credit' => $this->credit,
            'debit' => $this->debit,
            'amount' => $this->amount,
            'type' => $this->type,
            'balance' => $this->balance,
        ];
    }
}
