<?php

namespace Tests\Unit;

use App\Domain\Commission\RatePercent;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Partner\Actions\SyncIpRules;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PartnerRulesTest extends TestCase
{
    public function test_addresses_become_cidr_ranges()
    {
        $this->assertSame('52.66.45.184/32', SyncIpRules::toCidr(' 52.66.45.184 '));
        $this->assertSame('10.0.0.0/24', SyncIpRules::toCidr('10.0.0.0/24'));
        $this->assertSame('2001:db8::1/128', SyncIpRules::toCidr('2001:db8::1'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badAddresses(): array
    {
        return [
            'not an ip' => ['hello'],
            'octet too big' => ['256.1.1.1'],
            'whole internet' => ['0.0.0.0/0'],
            'too wide' => ['10.0.0.0/7'],
            'host bits set' => ['10.0.0.5/24'],
            'bad prefix' => ['1.2.3.4/33'],
        ];
    }

    #[DataProvider('badAddresses')]
    public function test_bad_addresses_are_refused(string $address)
    {
        $this->expectException(InvalidArgumentException::class);

        SyncIpRules::toCidr($address);
    }

    public function test_rates_compare_exactly_without_floats()
    {
        $this->assertSame('2.5000', RatePercent::normalize('2.5'));
        $this->assertSame(25000, RatePercent::units('2.5'));
        $this->assertSame(0, RatePercent::compare('2.5', '2.5000'));
        $this->assertSame(-1, RatePercent::compare('0.0001', '0.0002'));

        $this->expectException(InvalidArgumentException::class);
        RatePercent::units('100.0001');
    }

    public function test_rupees_convert_to_paise_exactly()
    {
        $this->assertSame(5000050, Money::toPaise('50000.50'));
        $this->assertSame(5000050, Money::toPaise('50,000.5'));
        $this->assertSame(10, Money::toPaise('0.1'));
        $this->assertNull(Money::toPaiseOrNull(''));
        $this->assertSame('1500.05', Money::toRupees(150005));
    }

    public function test_partner_lifecycle()
    {
        $this->assertTrue(OrganisationStatus::Draft->canMoveTo(OrganisationStatus::Active));
        $this->assertTrue(OrganisationStatus::Suspended->canMoveTo(OrganisationStatus::Active));
        $this->assertFalse(OrganisationStatus::Offboarded->canMoveTo(OrganisationStatus::Active));
        $this->assertFalse(OrganisationStatus::Draft->canMoveTo(OrganisationStatus::Suspended));
    }
}
