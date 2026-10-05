<?php

namespace FLAIRUK\SumUp\Tests;

use FLAIRUK\SumUp\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SumUp\Hydrator;
use SumUp\Types\GetReaderCheckoutResponseDataTotalAmount;

class MoneyTest extends TestCase
{
    #[Test]
    #[DataProvider('amounts')]
    public function amounts_are_parsed_exactly(string|int|float $amount, string $currency, int $minor, string $decimal): void
    {
        $money = Money::of($amount, $currency);

        $this->assertSame($minor, $money->minor);
        $this->assertSame($decimal, $money->decimal());
    }

    public static function amounts(): array
    {
        return [
            'string' => ['10.50', 'GBP', 1050, '10.50'],
            'no decimals' => ['10', 'EUR', 1000, '10.00'],
            'one decimal' => ['0.5', 'EUR', 50, '0.50'],
            'int' => [7, 'USD', 700, '7.00'],
            'float' => [19.99, 'EUR', 1999, '19.99'],
            'float drift' => [0.1 + 0.2, 'EUR', 30, '0.30'],
            'negative' => ['-2.05', 'GBP', -205, '-2.05'],
            'trailing zeros' => ['3.500', 'GBP', 350, '3.50'],
            'zero-decimal currency' => ['1500', 'CLP', 1500, '1500'],
            'three-decimal currency' => ['1.234', 'KWD', 1234, '1.234'],
            'lowercase currency' => ['1', 'gbp', 100, '1.00'],
        ];
    }

    #[Test]
    public function too_many_decimals_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('10.505', 'EUR');
    }

    #[Test]
    public function garbage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('ten', 'EUR');
    }

    #[Test]
    public function currencies_must_be_iso_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('1', 'pounds');
    }

    #[Test]
    public function it_converts_to_each_sumup_format(): void
    {
        $money = Money::ofMinor(1050, 'GBP');

        $this->assertSame(10.5, $money->amount());
        $this->assertSame(['value' => 1050, 'currency' => 'GBP', 'minor_unit' => 2], $money->toReaderAmount());
        $this->assertSame('10.50 GBP', (string) $money);
        $this->assertSame('{"amount":"10.50","currency":"GBP","minor":1050}', json_encode($money));
    }

    #[Test]
    public function it_reads_cloud_api_amounts(): void
    {
        $this->assertTrue(Money::fromReaderAmount(['value' => 1500, 'currency' => 'EUR', 'minor_unit' => 2])->equals(Money::of('15', 'EUR')));
        $this->assertTrue(Money::fromReaderAmount(['value' => 1500, 'currency' => 'EUR'])->equals(Money::of('15', 'EUR')));
        $this->assertTrue(Money::fromReaderAmount(['value' => 15, 'currency' => 'EUR', 'minor_unit' => 0])->equals(Money::of('15', 'EUR')));

        $sdkAmount = Hydrator::hydrate(['value' => 999, 'currency' => 'GBP', 'minor_unit' => 2], GetReaderCheckoutResponseDataTotalAmount::class);
        $this->assertTrue(Money::fromReaderAmount($sdkAmount)->equals(Money::of('9.99', 'GBP')));
    }

    #[Test]
    public function arithmetic_stays_in_one_currency(): void
    {
        $total = Money::of('10.00', 'GBP')->plus(Money::of('2.50', 'GBP'))->minus(Money::of('0.50', 'GBP'));

        $this->assertSame('12.00', $total->decimal());
        $this->assertFalse($total->isZero());
        $this->assertTrue(Money::ofMinor(0, 'GBP')->isZero());

        $this->expectException(InvalidArgumentException::class);
        $total->plus(Money::of('1', 'EUR'));
    }
}
