<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Reconciliation\Import\ColumnMapping;
use App\Domain\Reconciliation\Import\StatementParser;
use App\Domain\Transaction\Utr;
use InvalidArgumentException;
use Tests\TestCase;

class StatementParserTest extends TestCase
{
    public function test_amounts_are_read_the_way_indian_bank_statements_write_them()
    {
        $this->assertSame(12345650, StatementParser::parseAmount('1,23,456.50'));
        $this->assertSame(50000, StatementParser::parseAmount('₹ 500'));
        $this->assertSame(50000, StatementParser::parseAmount('500.00 Cr'));
        $this->assertSame(-50000, StatementParser::parseAmount('500.00 Dr'));
        $this->assertSame(-50000, StatementParser::parseAmount('(500.00)'));
        $this->assertSame(-50000, StatementParser::parseAmount('-500'));
        $this->assertSame(200050, StatementParser::parseAmount(2000.5));
        $this->assertNull(StatementParser::parseAmount(''));
        $this->assertNull(StatementParser::parseAmount(null));

        $this->expectException(InvalidArgumentException::class);
        StatementParser::parseAmount('12.345');
    }

    public function test_utrs_are_found_in_narrations()
    {
        $this->assertSame('626812820491', StatementParser::extractUtr('UPI/626812820491/Payment from Rahul/rahul@okaxis'));
        $this->assertSame('626812820491', StatementParser::extractUtr('IMPS-626812820491-RAHUL-SBIN0001234'));
        // A NEFT / RTGS reference is a UTR; other letters are not.
        $this->assertSame('HDFCN52026092812345678', StatementParser::extractUtr('NEFT-HDFCN52026092812345678-ACME LTD'));
        $this->assertNull(StatementParser::extractUtr('REF ABCDEF12 TRANSFER'));
        $this->assertSame('560925071657565', StatementParser::extractUtr('AIR-NASIR 560925071657565'));
        $this->assertNull(StatementParser::extractUtr('SMS CHARGES FOR SEP'));
    }

    public function test_utr_rules_follow_bank_formats()
    {
        // NEFT (16) and RTGS (22): banks write one or two letters after
        // the bank code (N, R, RC, CN…).
        foreach (['HDFCN52026092812', 'SBINR52026092812345678', 'HDFCRC2026092812345678', 'AXISCN0123456789'] as $reference) {
            $this->assertTrue(Utr::valid($reference), $reference);
        }

        foreach (['HDFCN5202609', 'PH611010921974763', 'S55442184', 'CICAGPI5UMJEDW', 'HDFC0001234'] as $other) {
            $this->assertFalse(Utr::valid($other) && preg_match(Utr::BANK_REFERENCE_PATTERN, $other) === 1, $other);
        }

        // In a statement's UTR column, a short number is a cheque / branch
        // reference, not a UTR: the narration's is used instead.
        $rows = [['Date', 'Narration', 'Chq No', 'Deposit'], ['25/09/2026', 'UPI/626812820491/RAHUL', '000123', '100']];
        $parsed = StatementParser::parse($rows, ColumnMapping::fromArray(['header_row' => 1, 'date' => 0, 'description' => 1, 'utr' => 2, 'credit' => 3]));
        $this->assertSame('626812820491', $parsed['lines'][0]['utr_normalized']);
    }

    public function test_rows_breaking_a_rule_are_reported_with_field_and_value()
    {
        $tomorrowPlus = now(config('app.business_timezone'))->addDays(5)->format('d/m/Y');
        $rows = [
            ['Date', 'Narration', 'Deposit'],
            ['25/09/2026', 'CASH DEPOSIT', '100'],
            [$tomorrowPlus, 'UPI/626812820491/RAHUL', '100'],
            ['25/09/2026', 'UPI/626812820492/AMIT', 'abc'],
        ];

        $parsed = StatementParser::parse($rows, ColumnMapping::fromArray(['header_row' => 1, 'date' => 0, 'description' => 1, 'credit' => 2]));

        $this->assertSame([], $parsed['lines']);
        $this->assertSame([[2, 'utr', ''], [3, 'date', $tomorrowPlus], [4, 'amount', 'abc']], array_map(fn (array $error) => [$error['row'], $error['field'], $error['value']], $parsed['errors']));
        $this->assertStringContainsString('in the future', $parsed['errors'][1]['message']);
    }

    public function test_dates_follow_the_chosen_format_and_excel_serials()
    {
        $this->assertSame('2026-09-25', StatementParser::parseDate('25/09/2026', 'd/m/Y'));
        $this->assertSame('2026-09-25', StatementParser::parseDate('25/09/26', 'd/m/y'));
        $this->assertSame('2026-09-25', StatementParser::parseDate('25-Sep-2026', 'd-M-Y'));
        $this->assertSame('2026-09-25', StatementParser::parseDate('25/09/2026 14:32:05', 'd/m/Y'));
        $this->assertSame('2026-09-25', StatementParser::parseDate('2026-09-25T09:15', 'Y-m-d'));
        $this->assertSame('2026-09-25', StatementParser::parseDate(46290.0, 'd/m/Y'));
        $this->assertNull(StatementParser::parseDate('31/02/2026', 'd/m/Y'));
        $this->assertNull(StatementParser::parseDate('09/25/2026', 'd/m/Y'));
    }
}
