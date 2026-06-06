<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Enums;

enum TransactionType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit  => 'Debit',
        };
    }
}
