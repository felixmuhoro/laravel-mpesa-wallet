<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Exceptions;

use RuntimeException;

final class WalletNotFoundException extends RuntimeException
{
    public function __construct(string $ownerType, int|string $ownerId)
    {
        parent::__construct("No wallet found for {$ownerType} #{$ownerId}.");
    }

    public static function forOwner(string $ownerType, int|string $ownerId): self
    {
        return new self($ownerType, $ownerId);
    }
}
