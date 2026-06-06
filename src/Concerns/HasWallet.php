<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Concerns;

use FelixMuhoro\MpesaWallet\Models\Wallet;
use FelixMuhoro\MpesaWallet\Models\WalletTransaction;
use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use FelixMuhoro\MpesaWallet\WalletManager;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Add this trait to any Eloquent model (typically User) to give it wallet capabilities.
 *
 * Usage:
 *   class User extends Authenticatable
 *   {
 *       use \FelixMuhoro\MpesaWallet\Concerns\HasWallet;
 *   }
 */
trait HasWallet
{
    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function wallets(): MorphMany
    {
        return $this->morphMany(Wallet::class, 'owner');
    }

    /**
     * Returns the primary wallet, auto-creating it if configured to do so.
     */
    public function wallet(): Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->wallets()->first();

        if ($wallet === null && config('mpesa-wallet.auto_create', true)) {
            $wallet = $this->wallets()->create([
                'currency' => config('mpesa-wallet.currency', 'KES'),
            ]);
        }

        return $wallet;
    }

    // -------------------------------------------------------------------------
    // Balance convenience
    // -------------------------------------------------------------------------

    public function walletBalance(): Money
    {
        return $this->wallet()->balance();
    }

    public function formattedWalletBalance(): string
    {
        return $this->wallet()->formattedBalance();
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    public function deposit(int $amount, string $phone, array $meta = []): WalletTransaction
    {
        return app(WalletManager::class)->deposit($this, $amount, $phone, $meta);
    }

    public function withdraw(int $amount, string $phone, array $meta = []): WalletTransaction
    {
        return app(WalletManager::class)->withdraw($this, $amount, $phone, $meta);
    }

    /**
     * @return array{0: WalletTransaction, 1: WalletTransaction}
     */
    public function transferTo(self $recipient, int $amount, array $meta = []): array
    {
        return app(WalletManager::class)->transfer($this, $recipient, $amount, $meta);
    }

    public function hasWallet(): bool
    {
        return $this->wallets()->exists();
    }
}
