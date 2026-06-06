<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Exceptions;

use RuntimeException;

final class WalletFrozenException extends RuntimeException
{
    public function __construct(int|string $walletId)
    {
        parent::__construct("Wallet #{$walletId} is frozen and cannot process transactions.");
    }

    public static function forWallet(int|string $walletId): self
    {
        return new self($walletId);
    }
}
