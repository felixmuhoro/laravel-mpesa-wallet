<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Models;

use FelixMuhoro\MpesaWallet\Enums\TransactionStatus;
use FelixMuhoro\MpesaWallet\Enums\TransactionType;
use FelixMuhoro\MpesaWallet\Exceptions\InsufficientBalanceException;
use FelixMuhoro\MpesaWallet\Exceptions\WalletFrozenException;
use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $balance         Balance in whole KES units
 * @property int         $locked_balance  Amount locked pending outgoing STK confirmations
 * @property string      $currency        ISO 4217, e.g. "KES"
 * @property bool        $is_frozen
 * @property string      $owner_type
 * @property int|string  $owner_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Wallet extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'balance'        => 'integer',
            'locked_balance' => 'integer',
            'is_frozen'      => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return config('mpesa-wallet.tables.wallets', 'wallets');
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function pendingTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)
            ->where('status', TransactionStatus::Pending->value);
    }

    // -------------------------------------------------------------------------
    // Balance helpers
    // -------------------------------------------------------------------------

    /**
     * Spendable balance (total minus locked funds).
     */
    public function balance(): Money
    {
        return Money::of($this->balance - $this->locked_balance, $this->currency);
    }

    public function totalBalance(): Money
    {
        return Money::of($this->balance, $this->currency);
    }

    public function lockedBalance(): Money
    {
        return Money::of($this->locked_balance, $this->currency);
    }

    public function formattedBalance(): string
    {
        return $this->balance()->format();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Credit the wallet and record a completed transaction.
     * Must be called inside a DB transaction with the row locked via lockForUpdate().
     */
    public function deposit(Money $amount, array $meta = [], ?string $reference = null): WalletTransaction
    {
        $this->assertNotFrozen();
        $this->increment('balance', $amount->amount);

        return $this->recordTransaction(
            type: TransactionType::Credit,
            amount: $amount,
            status: TransactionStatus::Completed,
            meta: $meta,
            reference: $reference ?? Str::uuid()->toString(),
        );
    }

    /**
     * Debit the wallet, checking spendable balance first.
     * Must be called inside a DB transaction with lockForUpdate().
     */
    public function withdraw(Money $amount, array $meta = [], ?string $reference = null): WalletTransaction
    {
        $this->assertNotFrozen();
        $this->assertSufficientBalance($amount);
        $this->decrement('balance', $amount->amount);

        return $this->recordTransaction(
            type: TransactionType::Debit,
            amount: $amount,
            status: TransactionStatus::Completed,
            meta: $meta,
            reference: $reference ?? Str::uuid()->toString(),
        );
    }

    /**
     * Transfer funds to another wallet (both must be locked before calling).
     *
     * @return array{0: WalletTransaction, 1: WalletTransaction}
     */
    public function transfer(Wallet $destination, Money $amount, array $meta = []): array
    {
        $this->assertNotFrozen();
        $destination->assertNotFrozen();
        $this->assertSufficientBalance($amount);

        $ref    = Str::uuid()->toString();
        $debit  = $this->withdraw($amount, array_merge($meta, ['transfer_to' => $destination->uuid]), $ref);
        $credit = $destination->deposit($amount, array_merge($meta, ['transfer_from' => $this->uuid]), $ref);

        return [$debit, $credit];
    }

    /**
     * Lock funds pending an outgoing STK push; creates a pending debit transaction.
     */
    public function lockFunds(Money $amount, array $meta = [], ?string $reference = null): WalletTransaction
    {
        $this->assertNotFrozen();
        $this->assertSufficientBalance($amount);
        $this->increment('locked_balance', $amount->amount);

        return $this->recordTransaction(
            type: TransactionType::Debit,
            amount: $amount,
            status: TransactionStatus::Pending,
            meta: $meta,
            reference: $reference ?? Str::uuid()->toString(),
        );
    }

    /**
     * Release previously locked funds (STK failed or timed out).
     */
    public function releaseLock(WalletTransaction $transaction): void
    {
        if ($transaction->status !== TransactionStatus::Pending) {
            return;
        }

        $this->decrement('locked_balance', $transaction->amount);
        $transaction->update(['status' => TransactionStatus::Failed->value]);
    }

    /**
     * Settle a pending locked transaction (STK/B2C succeeded).
     */
    public function settleLock(WalletTransaction $transaction): void
    {
        if ($transaction->status !== TransactionStatus::Pending) {
            return;
        }

        $this->decrement('balance', $transaction->amount);
        $this->decrement('locked_balance', $transaction->amount);
        $transaction->update(['status' => TransactionStatus::Completed->value]);
    }

    // -------------------------------------------------------------------------
    // Freeze / unfreeze
    // -------------------------------------------------------------------------

    public function freeze(): void
    {
        $this->update(['is_frozen' => true]);
    }

    public function unfreeze(): void
    {
        $this->update(['is_frozen' => false]);
    }

    public function isFrozen(): bool
    {
        return (bool) $this->is_frozen;
    }

    // -------------------------------------------------------------------------
    // Internal guards
    // -------------------------------------------------------------------------

    private function assertNotFrozen(): void
    {
        if ($this->is_frozen) {
            throw WalletFrozenException::forWallet($this->uuid);
        }
    }

    private function assertSufficientBalance(Money $amount): void
    {
        $spendable = $this->balance();

        if ($amount->isGreaterThan($spendable)) {
            throw InsufficientBalanceException::make($spendable, $amount);
        }
    }

    private function recordTransaction(
        TransactionType $type,
        Money $amount,
        TransactionStatus $status,
        array $meta,
        string $reference,
    ): WalletTransaction {
        /** @var WalletTransaction */
        return $this->transactions()->create([
            'uuid'      => Str::uuid()->toString(),
            'type'      => $type->value,
            'amount'    => $amount->amount,
            'currency'  => $amount->currency,
            'status'    => $status->value,
            'meta'      => $meta,
            'reference' => $reference,
        ]);
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (Wallet $wallet): void {
            if (empty($wallet->uuid)) {
                $wallet->uuid = Str::uuid()->toString();
            }
            if (empty($wallet->currency)) {
                $wallet->currency = config('mpesa-wallet.currency', 'KES');
            }
            if ($wallet->balance === null) {
                $wallet->balance = 0;
            }
            if ($wallet->locked_balance === null) {
                $wallet->locked_balance = 0;
            }
            if ($wallet->is_frozen === null) {
                $wallet->is_frozen = false;
            }
        });
    }
}
