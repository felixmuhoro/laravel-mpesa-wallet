<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Models;

use FelixMuhoro\MpesaWallet\Enums\TransactionStatus;
use FelixMuhoro\MpesaWallet\Enums\TransactionType;
use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int               $id
 * @property string            $uuid
 * @property int               $wallet_id
 * @property TransactionType   $type
 * @property int               $amount      Amount in whole KES units
 * @property string            $currency
 * @property array             $meta
 * @property TransactionStatus $status
 * @property string            $reference   Correlation ID (M-Pesa CheckoutRequestID, etc.)
 * @property \Carbon\Carbon    $created_at
 * @property \Carbon\Carbon    $updated_at
 * @property Wallet            $wallet
 */
class WalletTransaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type'   => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'meta'   => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('mpesa-wallet.tables.transactions', 'wallet_transactions');
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Pending->value);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Completed->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Failed->value);
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Credit->value);
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Debit->value);
    }

    public function scopeByReference(Builder $query, string $reference): Builder
    {
        return $query->where('reference', $reference);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency ?? $this->wallet->currency);
    }

    public function isCredit(): bool
    {
        return $this->type === TransactionType::Credit;
    }

    public function isDebit(): bool
    {
        return $this->type === TransactionType::Debit;
    }

    public function isPending(): bool
    {
        return $this->status === TransactionStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === TransactionStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === TransactionStatus::Failed;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (WalletTransaction $tx): void {
            if (empty($tx->uuid)) {
                $tx->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
            if (empty($tx->status)) {
                $tx->status = TransactionStatus::Pending;
            }
        });
    }
}
