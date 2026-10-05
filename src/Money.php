<?php

namespace FLAIRUK\SumUp;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money held as an integer number of minor units (pence, cents).
 *
 * SumUp's online Checkouts API takes major units ("amount": 10.5), while the
 * Cloud API for card readers takes minor units ({"value": 1050, "minor_unit": 2}).
 * Money converts between the two without floating-point drift.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /**
     * ISO 4217 currencies whose minor unit is not 2 decimal places.
     *
     * @var array<string, int>
     */
    private const MINOR_UNITS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public string $currency;

    private function __construct(public int $minor, string $currency)
    {
        if (! preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw new InvalidArgumentException("[{$currency}] is not an ISO 4217 currency code.");
        }

        $this->currency = strtoupper($currency);
    }

    /**
     * From a major-unit amount: Money::of('10.50', 'GBP'), Money::of(10, 'EUR').
     *
     * Strings are parsed exactly and may not have more decimals than the currency allows.
     * Floats are rounded to the currency's minor unit.
     */
    public static function of(string|int|float $amount, string $currency): self
    {
        $exponent = self::minorUnitFor($currency);

        if (is_int($amount)) {
            return new self($amount * 10 ** $exponent, $currency);
        }

        if (is_float($amount)) {
            return new self((int) round($amount * 10 ** $exponent), $currency);
        }

        $amount = trim($amount);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $amount, $parts)) {
            throw new InvalidArgumentException("[{$amount}] is not a valid amount.");
        }

        $fraction = $parts[3] ?? '';

        if (strlen(rtrim($fraction, '0')) > $exponent) {
            throw new InvalidArgumentException("[{$amount}] has more decimal places than {$currency} allows ({$exponent}).");
        }

        $minor = (int) ($parts[2].str_pad(substr($fraction, 0, $exponent), $exponent, '0'));

        return new self($parts[1] === '-' ? -$minor : $minor, $currency);
    }

    /**
     * From minor units: Money::ofMinor(1050, 'GBP') is £10.50.
     */
    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, $currency);
    }

    /**
     * From a Cloud API amount: ['value' => 1050, 'currency' => 'GBP', 'minor_unit' => 2],
     * or the SDK object with value, currency and minorUnit properties.
     *
     * @param  array{value: int, currency: string, minor_unit?: int}|object  $amount
     */
    public static function fromReaderAmount(array|object $amount): self
    {
        $amount = is_array($amount) ? $amount : [
            'value' => $amount->value,
            'currency' => $amount->currency,
            'minor_unit' => $amount->minorUnit ?? null,
        ];

        $money = new self((int) $amount['value'], $amount['currency']);
        $minorUnit = $amount['minor_unit'] ?? $money->minorUnit();

        if ($minorUnit !== $money->minorUnit()) {
            return self::of(self::format((int) $amount['value'], (int) $minorUnit), $amount['currency']);
        }

        return $money;
    }

    /**
     * The number of decimal places in the currency's minor unit (2 for GBP, 0 for CLP).
     */
    public static function minorUnitFor(string $currency): int
    {
        return self::MINOR_UNITS[strtoupper($currency)] ?? 2;
    }

    public function minorUnit(): int
    {
        return self::minorUnitFor($this->currency);
    }

    /**
     * The amount in major units, as SumUp's Checkouts API expects it.
     */
    public function amount(): float
    {
        return (float) $this->decimal();
    }

    /**
     * The amount as an exact decimal string, e.g. "10.50".
     */
    public function decimal(): string
    {
        return self::format($this->minor, $this->minorUnit());
    }

    /**
     * The amount as the Cloud API expects it: ['value' => 1050, 'currency' => 'GBP', 'minor_unit' => 2].
     *
     * @return array{value: int, currency: string, minor_unit: int}
     */
    public function toReaderAmount(): array
    {
        return ['value' => $this->minor, 'currency' => $this->currency, 'minor_unit' => $this->minorUnit()];
    }

    public function plus(self $other): self
    {
        return new self($this->minor + $this->sameCurrency($other)->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        return new self($this->minor - $this->sameCurrency($other)->minor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /**
     * @return array{amount: string, currency: string, minor: int}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->decimal(), 'currency' => $this->currency, 'minor' => $this->minor];
    }

    public function __toString(): string
    {
        return $this->decimal().' '.$this->currency;
    }

    private static function format(int $minor, int $exponent): string
    {
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);
        $sign = $minor < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    private function sameCurrency(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} and {$other->currency}.");
        }

        return $other;
    }
}
