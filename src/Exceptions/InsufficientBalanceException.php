<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Exceptions;

use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use RuntimeException;

final class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly Money $available,
        public readonly Money $requested,
    ) {
        parent::__construct(sprintf(
            'Insufficient wallet balance. Available: %s, Requested: %s.',
            $available->format(),
            $requested->format(),
        ));
    }

    public static function make(Money $available, Money $requested): self
    {
        return new self($available, $requested);
    }
}
