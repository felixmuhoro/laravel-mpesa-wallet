<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable value object representing a monetary amount with a currency.
 *
 * Arithmetic is performed on integers (whole KES units) to avoid
 * floating-point precision errors. M-Pesa does not deal in sub-shilling amounts.
 */
final class Money
{
    public readonly int $minorUnits;

    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException("Money amount cannot be negative. Got: {$amount}");
        }

        if (strlen(trim($currency)) !== 3) {
            throw new InvalidArgumentException("Currency must be a 3-character ISO 4217 code. Got: {$currency}");
        }

        $this->minorUnits = $amount;
    }

    public static function of(int|float $amount, string $currency): self
    {
        return new self((int) round((float) $amount), strtoupper(trim($currency)));
    }

    public static function zero(string $currency = 'KES'): self
    {
        return new self(0, strtoupper(trim($currency)));
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->amount > $this->amount) {
            throw new InvalidArgumentException(
                "Cannot subtract {$other} from {$this}: result would be negative."
            );
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isGreaterThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount >= $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function format(): string
    {
        return sprintf('%s %s', $this->currency, number_format($this->amount, 2));
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}."
            );
        }
    }
}
