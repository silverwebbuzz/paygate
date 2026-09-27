<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Reconciliation\Import\StatementParser;
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
        $this->assertSame('HDFCN52026092812345678', StatementParser::extractUtr('NEFT-HDFCN52026092812345678-ACME LTD'));
        $this->assertSame('560925071657565', StatementParser::extractUtr('AIR-NASIR 560925071657565'));
        $this->assertNull(StatementParser::extractUtr('SMS CHARGES FOR SEP'));
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
