<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Http\Resources;

use FelixMuhoro\MpesaWallet\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Wallet */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->uuid,
            'currency'          => $this->currency,
            'balance'           => $this->balance()->amount,
            'balance_formatted' => $this->formattedBalance(),
            'locked_balance'    => $this->locked_balance,
            'is_frozen'         => $this->is_frozen,
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
