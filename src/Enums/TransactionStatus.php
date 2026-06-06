<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Enums;

enum TransactionStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Failed    = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending                 => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Completed => 'Completed',
            self::Failed    => 'Failed',
        };
    }
}
