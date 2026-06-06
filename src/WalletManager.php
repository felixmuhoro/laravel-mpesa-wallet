<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet;

use FelixMuhoro\MpesaWallet\Exceptions\TransactionLimitException;
use FelixMuhoro\MpesaWallet\Exceptions\WalletNotFoundException;
use FelixMuhoro\MpesaWallet\Models\Wallet;
use FelixMuhoro\MpesaWallet\Models\WalletTransaction;
use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Orchestrates wallet lifecycle and M-Pesa-backed fund movements.
 *
 * All balance-mutating operations run inside explicit DB transactions with
 * SELECT ... FOR UPDATE row-level locks to prevent double-spend under concurrency.
 */
final class WalletManager
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {}

    // -------------------------------------------------------------------------
    // Wallet provisioning
    // -------------------------------------------------------------------------

    public function createWallet(Model $owner, ?string $currency = null): Wallet
    {
        $currency = strtoupper($currency ?? $this->config->get('mpesa-wallet.currency', 'KES'));

        /** @var Wallet */
        return $owner->morphMany(Wallet::class, 'owner')->create([
            'currency' => $currency,
        ]);
    }

    /**
     * @throws WalletNotFoundException
     */
    public function getWallet(Model $owner): Wallet
    {
        $wallet = $owner->morphMany(Wallet::class, 'owner')->first();

        if ($wallet === null) {
            throw WalletNotFoundException::forOwner($owner::class, $owner->getKey());
        }

        return $wallet;
    }

    public function findOrCreateWallet(Model $owner, ?string $currency = null): Wallet
    {
        $wallet = $owner->morphMany(Wallet::class, 'owner')->first();

        if ($wallet === null) {
            $wallet = $this->createWallet($owner, $currency);
        }

        return $wallet;
    }

    // -------------------------------------------------------------------------
    // Deposits
    // -------------------------------------------------------------------------

    /**
     * Register a pending credit transaction (STK push should be initiated by the caller).
     */
    public function deposit(
        Model $owner,
        int $amount,
        string $phone,
        array $meta = [],
    ): WalletTransaction {
        $currency = $this->config->get('mpesa-wallet.currency', 'KES');
        $money    = Money::of($amount, $currency);

        $this->assertWithinLimits('deposit', $money);

        $wallet = $this->findOrCreateWallet($owner);

        return $this->db->transaction(function () use ($wallet, $money, $phone, $meta): WalletTransaction {
            /** @var Wallet $locked */
            $locked = Wallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $locked->transactions()->create([
                'uuid'      => \Illuminate\Support\Str::uuid()->toString(),
                'type'      => \FelixMuhoro\MpesaWallet\Enums\TransactionType::Credit->value,
                'amount'    => $money->amount,
                'currency'  => $money->currency,
                'status'    => \FelixMuhoro\MpesaWallet\Enums\TransactionStatus::Pending->value,
                'meta'      => array_merge($meta, ['phone' => $phone]),
                'reference' => \Illuminate\Support\Str::uuid()->toString(),
            ]);
        });
    }

    /**
     * Directly credit a wallet (called after confirmed STK callback).
     */
    public function creditWallet(Wallet $wallet, Money $money, array $meta = [], ?string $reference = null): WalletTransaction
    {
        return $this->db->transaction(function () use ($wallet, $money, $meta, $reference): WalletTransaction {
            /** @var Wallet $locked */
            $locked = Wallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $locked->deposit($money, $meta, $reference);
        });
    }

    // -------------------------------------------------------------------------
    // Withdrawals
    // -------------------------------------------------------------------------

    /**
     * Lock funds and register a pending debit (B2C should be initiated by caller).
     */
    public function withdraw(
        Model $owner,
        int $amount,
        string $phone,
        array $meta = [],
    ): WalletTransaction {
        $currency = $this->config->get('mpesa-wallet.currency', 'KES');
        $money    = Money::of($amount, $currency);

        $this->assertWithinLimits('withdrawal', $money);

        $wallet = $this->getWallet($owner);

        return $this->db->transaction(function () use ($wallet, $money, $phone, $meta): WalletTransaction {
            /** @var Wallet $locked */
            $locked = Wallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $locked->lockFunds(
                $money,
                array_merge($meta, ['phone' => $phone]),
            );
        });
    }

    public function settleWithdrawal(WalletTransaction $transaction): void
    {
        $this->db->transaction(function () use ($transaction): void {
            $locked = Wallet::query()
                ->where('id', $transaction->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->settleLock($transaction);
        });
    }

    public function failWithdrawal(WalletTransaction $transaction): void
    {
        $this->db->transaction(function () use ($transaction): void {
            $locked = Wallet::query()
                ->where('id', $transaction->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->releaseLock($transaction);
        });
    }

    // -------------------------------------------------------------------------
    // Transfers
    // -------------------------------------------------------------------------

    /**
     * Move funds between two wallets atomically.
     * Wallets are locked in ascending ID order to prevent deadlocks.
     *
     * @return array{0: WalletTransaction, 1: WalletTransaction}
     */
    public function transfer(
        Model $from,
        Model $to,
        int $amount,
        array $meta = [],
    ): array {
        $currency   = $this->config->get('mpesa-wallet.currency', 'KES');
        $money      = Money::of($amount, $currency);
        $fromWallet = $this->getWallet($from);
        $toWallet   = $this->getWallet($to);

        return $this->db->transaction(function () use ($fromWallet, $toWallet, $money, $meta): array {
            // Lock in consistent order to prevent deadlocks between concurrent transfers.
            [$firstId, $secondId] = $fromWallet->id < $toWallet->id
                ? [$fromWallet->id, $toWallet->id]
                : [$toWallet->id, $fromWallet->id];

            $wallets = Wallet::query()
                ->whereIn('id', [$firstId, $secondId])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedFrom = $wallets->get($fromWallet->id);
            $lockedTo   = $wallets->get($toWallet->id);

            return $lockedFrom->transfer($lockedTo, $money, $meta);
        });
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function assertWithinLimits(string $direction, Money $money): void
    {
        $cfg    = $this->config->get('mpesa-wallet.limits', []);
        $minKey = "min_{$direction}";
        $maxKey = "max_{$direction}";

        if (isset($cfg[$minKey]) && $money->amount < (int) $cfg[$minKey]) {
            throw TransactionLimitException::tooLow(
                $direction,
                $money,
                Money::of((int) $cfg[$minKey], $money->currency),
            );
        }

        if (isset($cfg[$maxKey]) && $money->amount > (int) $cfg[$maxKey]) {
            throw TransactionLimitException::tooHigh(
                $direction,
                $money,
                Money::of((int) $cfg[$maxKey], $money->currency),
            );
        }
    }
}
