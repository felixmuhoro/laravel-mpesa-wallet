<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Http\Resources;

use FelixMuhoro\MpesaWallet\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletTransaction */
class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->uuid,
            'type'         => $this->type?->value,
            'type_label'   => $this->type?->label(),
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'formatted'    => $this->money()->format(),
            'status'       => $this->status?->value,
            'status_label' => $this->status?->label(),
            'reference'    => $this->reference,
            'meta'         => $this->meta,
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
