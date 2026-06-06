<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Exceptions;

use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use RuntimeException;

final class TransactionLimitException extends RuntimeException
{
    public function __construct(string $direction, Money $amount, Money $limit)
    {
        parent::__construct(sprintf(
            '%s amount %s exceeds the configured %s limit of %s.',
            ucfirst($direction),
            $amount->format(),
            $direction,
            $limit->format(),
        ));
    }

    public static function tooHigh(string $direction, Money $amount, Money $limit): self
    {
        return new self($direction, $amount, $limit);
    }

    public static function tooLow(string $direction, Money $amount, Money $minimum): self
    {
        return new self("minimum {$direction}", $amount, $minimum);
    }
}
