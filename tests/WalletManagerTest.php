<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Tests;

use FelixMuhoro\MpesaWallet\Enums\TransactionStatus;
use FelixMuhoro\MpesaWallet\Enums\TransactionType;
use FelixMuhoro\MpesaWallet\Exceptions\InsufficientBalanceException;
use FelixMuhoro\MpesaWallet\Exceptions\TransactionLimitException;
use FelixMuhoro\MpesaWallet\Exceptions\WalletFrozenException;
use FelixMuhoro\MpesaWallet\Exceptions\WalletNotFoundException;
use FelixMuhoro\MpesaWallet\Models\Wallet;
use FelixMuhoro\MpesaWallet\Models\WalletTransaction;
use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use FelixMuhoro\MpesaWallet\WalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Integration tests for WalletManager.
 *
 * Run with: vendor/bin/phpunit --testdox
 */
class WalletManagerTest extends TestCase
{
    use RefreshDatabase;

    private WalletManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(WalletManager::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            \FelixMuhoro\MpesaWallet\MpesaWalletServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('mpesa-wallet.currency', 'KES');
        $app['config']->set('mpesa-wallet.auto_create', true);
        $app['config']->set('mpesa-wallet.limits', [
            'min_deposit'    => 10,
            'max_deposit'    => 150_000,
            'min_withdrawal' => 10,
            'max_withdrawal' => 150_000,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(int $id = 1): FakeUser
    {
        $user     = new FakeUser();
        $user->id = $id;
        $user->save();

        return $user;
    }

    // -------------------------------------------------------------------------
    // Wallet creation
    // -------------------------------------------------------------------------

    /** @test */
    public function it_creates_a_wallet_for_an_owner(): void
    {
        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertSame('KES', $wallet->currency);
        $this->assertSame(0, $wallet->balance);
        $this->assertSame(0, $wallet->locked_balance);
        $this->assertFalse($wallet->is_frozen);
        $this->assertNotEmpty($wallet->uuid);
    }

    /** @test */
    public function it_retrieves_an_existing_wallet(): void
    {
        $user      = $this->makeUser();
        $created   = $this->manager->createWallet($user);
        $retrieved = $this->manager->getWallet($user);

        $this->assertSame($created->id, $retrieved->id);
    }

    /** @test */
    public function it_throws_when_wallet_not_found(): void
    {
        $this->expectException(WalletNotFoundException::class);

        $user = $this->makeUser();
        $this->manager->getWallet($user);
    }

    /** @test */
    public function find_or_create_returns_existing_wallet(): void
    {
        $user   = $this->makeUser();
        $first  = $this->manager->findOrCreateWallet($user);
        $second = $this->manager->findOrCreateWallet($user);

        $this->assertSame($first->id, $second->id);
    }

    // -------------------------------------------------------------------------
    // Deposits
    // -------------------------------------------------------------------------

    /** @test */
    public function deposit_creates_a_pending_credit_transaction(): void
    {
        $user = $this->makeUser();
        $txn  = $this->manager->deposit($user, 500, '254700000001');

        $this->assertInstanceOf(WalletTransaction::class, $txn);
        $this->assertSame(TransactionType::Credit, $txn->type);
        $this->assertSame(TransactionStatus::Pending, $txn->status);
        $this->assertSame(500, $txn->amount);
        $this->assertSame('254700000001', $txn->meta['phone']);
    }

    /** @test */
    public function credit_wallet_increases_balance_and_creates_completed_transaction(): void
    {
        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $money  = Money::of(1000, 'KES');

        $txn = $this->manager->creditWallet($wallet, $money, ['note' => 'test']);

        $wallet->refresh();
        $this->assertSame(1000, $wallet->balance);
        $this->assertSame(TransactionStatus::Completed, $txn->status);
        $this->assertSame(TransactionType::Credit, $txn->type);
    }

    /** @test */
    public function deposit_rejects_amount_below_minimum(): void
    {
        $this->expectException(TransactionLimitException::class);

        $user = $this->makeUser();
        $this->manager->deposit($user, 5, '254700000001');
    }

    /** @test */
    public function deposit_rejects_amount_above_maximum(): void
    {
        $this->expectException(TransactionLimitException::class);

        $user = $this->makeUser();
        $this->manager->deposit($user, 200_000, '254700000001');
    }

    // -------------------------------------------------------------------------
    // Withdrawals
    // -------------------------------------------------------------------------

    /** @test */
    public function withdraw_locks_funds_and_creates_pending_debit(): void
    {
        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $this->manager->creditWallet($wallet, Money::of(2000, 'KES'));
        $wallet->refresh();

        $txn = $this->manager->withdraw($user, 500, '254700000001');

        $wallet->refresh();

        $this->assertSame(TransactionType::Debit, $txn->type);
        $this->assertSame(TransactionStatus::Pending, $txn->status);
        $this->assertSame(500, $txn->amount);
        $this->assertSame(500, $wallet->locked_balance);
        $this->assertSame(1500, $wallet->balance()->amount);
    }

    /** @test */
    public function withdraw_throws_on_insufficient_balance(): void
    {
        $this->expectException(InsufficientBalanceException::class);

        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $this->manager->creditWallet($wallet, Money::of(100, 'KES'));

        $this->manager->withdraw($user, 500, '254700000001');
    }

    /** @test */
    public function settle_withdrawal_deducts_balance_and_clears_lock(): void
    {
        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $this->manager->creditWallet($wallet, Money::of(2000, 'KES'));
        $wallet->refresh();

        $txn = $this->manager->withdraw($user, 500, '254700000001');
        $this->manager->settleWithdrawal($txn);
        $wallet->refresh();

        $this->assertSame(1500, $wallet->balance);
        $this->assertSame(0, $wallet->locked_balance);
        $this->assertSame(TransactionStatus::Completed, $txn->fresh()->status);
    }

    /** @test */
    public function fail_withdrawal_releases_lock_without_deducting_balance(): void
    {
        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $this->manager->creditWallet($wallet, Money::of(2000, 'KES'));
        $wallet->refresh();

        $txn = $this->manager->withdraw($user, 500, '254700000001');

        $this->manager->failWithdrawal($txn);
        $wallet->refresh();

        $this->assertSame(2000, $wallet->balance);
        $this->assertSame(0, $wallet->locked_balance);
        $this->assertSame(TransactionStatus::Failed, $txn->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Transfers
    // -------------------------------------------------------------------------

    /** @test */
    public function transfer_moves_funds_between_two_wallets(): void
    {
        $alice = $this->makeUser(1);
        $bob   = $this->makeUser(2);

        $aliceWallet = $this->manager->createWallet($alice);
        $bobWallet   = $this->manager->createWallet($bob);

        $this->manager->creditWallet($aliceWallet, Money::of(3000, 'KES'));
        $aliceWallet->refresh();

        [$debit, $credit] = $this->manager->transfer($alice, $bob, 1000);

        $aliceWallet->refresh();
        $bobWallet->refresh();

        $this->assertSame(2000, $aliceWallet->balance);
        $this->assertSame(1000, $bobWallet->balance);
        $this->assertSame($debit->reference, $credit->reference);
        $this->assertSame(TransactionType::Debit, $debit->type);
        $this->assertSame(TransactionType::Credit, $credit->type);
    }

    /** @test */
    public function transfer_throws_on_insufficient_balance(): void
    {
        $this->expectException(InsufficientBalanceException::class);

        $alice = $this->makeUser(1);
        $bob   = $this->makeUser(2);
        $this->manager->createWallet($alice);
        $this->manager->createWallet($bob);

        $this->manager->transfer($alice, $bob, 100);
    }

    // -------------------------------------------------------------------------
    // Frozen wallets
    // -------------------------------------------------------------------------

    /** @test */
    public function frozen_wallet_rejects_credits(): void
    {
        $this->expectException(WalletFrozenException::class);

        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $wallet->freeze();

        $this->manager->creditWallet($wallet, Money::of(100, 'KES'));
    }

    /** @test */
    public function frozen_wallet_rejects_debits(): void
    {
        $this->expectException(WalletFrozenException::class);

        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $this->manager->creditWallet($wallet, Money::of(1000, 'KES'));
        $wallet->refresh();
        $wallet->freeze();

        $this->manager->withdraw($user, 100, '254700000001');
    }

    /** @test */
    public function unfreezing_wallet_allows_operations(): void
    {
        $user   = $this->makeUser();
        $wallet = $this->manager->createWallet($user);
        $wallet->freeze();
        $wallet->unfreeze();

        $txn = $this->manager->creditWallet($wallet, Money::of(100, 'KES'));
        $this->assertSame(TransactionStatus::Completed, $txn->status);
    }

    // -------------------------------------------------------------------------
    // Money value object
    // -------------------------------------------------------------------------

    /** @test */
    public function money_addition_works_correctly(): void
    {
        $a = Money::of(500, 'KES');
        $b = Money::of(300, 'KES');

        $this->assertSame(800, $a->add($b)->amount);
    }

    /** @test */
    public function money_subtraction_works_correctly(): void
    {
        $a = Money::of(500, 'KES');
        $b = Money::of(200, 'KES');

        $this->assertSame(300, $a->subtract($b)->amount);
    }

    /** @test */
    public function money_subtraction_throws_on_underflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(100, 'KES')->subtract(Money::of(200, 'KES'));
    }

    /** @test */
    public function money_rejects_negative_amounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(-1, 'KES');
    }

    /** @test */
    public function money_currency_mismatch_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(100, 'KES')->add(Money::of(100, 'USD'));
    }

    /** @test */
    public function money_formats_correctly(): void
    {
        $m = Money::of(1250, 'KES');

        $this->assertSame('KES 1,250.00', $m->format());
    }
}

// ---------------------------------------------------------------------------
// Minimal in-memory User model for tests
// ---------------------------------------------------------------------------

class FakeUser extends \Illuminate\Database\Eloquent\Model
{
    use \FelixMuhoro\MpesaWallet\Concerns\HasWallet;

    protected $table    = 'fake_users';
    protected $fillable = ['id'];

    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (): void {
            if (! \Illuminate\Support\Facades\Schema::hasTable('fake_users')) {
                \Illuminate\Support\Facades\Schema::create('fake_users', static function (\Illuminate\Database\Schema\Blueprint $t): void {
                    $t->id();
                });
            }
        });
    }
}
