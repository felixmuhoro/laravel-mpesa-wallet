# laravel-mpesa-wallet

A production-ready virtual wallet system for Laravel, backed by M-Pesa deposits and withdrawals via [`felixmuhoro/laravel-mpesa`](https://github.com/felixmuhoro/laravel-mpesa).

## Features

- **Virtual wallet** per Eloquent owner (User, Merchant, etc.) with KES balance tracking
- **M-Pesa STK Push deposits** — registers pending transaction, credits wallet on callback
- **M-Pesa B2C withdrawals** — locks funds atomically, settles or refunds on callback
- **Wallet-to-wallet transfers** — deadlock-safe using ordered `SELECT ... FOR UPDATE`
- **Freeze / unfreeze** wallets to block all transactions
- **Locked balance** tracks in-flight funds without double-spending
- **Enum-typed** transaction status and type, immutable `Money` value object
- **REST API** with resource classes, pagination, and validation
- **Event listener** (`CreditWalletOnPayment`) auto-credits wallet on `PaymentSuccessful`
- **Zero race conditions** — all mutations use DB-level row locks inside transactions

---

## Requirements

| Dependency                  | Version                                    |
|-----------------------------|--------------------------------------------|
| PHP                         | `^8.1`                                     |
| Laravel                     | `^10.0 || ^11.0 || ^12.0 || ^13.0`         |
| felixmuhoro/laravel-mpesa   | `^1.2`                                     |

---

## Installation

```bash
composer require felixmuhoro/laravel-mpesa-wallet
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag=mpesa-wallet-config
php artisan vendor:publish --tag=mpesa-wallet-migrations
php artisan migrate
```

---

## Setup

### 1. Add the trait to your User model

```php
use FelixMuhoro\MpesaWallet\Concerns\HasWallet;

class User extends Authenticatable
{
    use HasWallet;
}
```

### 2. Configure `.env`

```dotenv
MPESA_WALLET_CURRENCY=KES
MPESA_WALLET_MIN_DEPOSIT=10
MPESA_WALLET_MAX_DEPOSIT=150000
MPESA_WALLET_MIN_WITHDRAWAL=10
MPESA_WALLET_MAX_WITHDRAWAL=150000
MPESA_WALLET_CALLBACK_URL=https://yourdomain.com/api/mpesa/callback
```

---

## Usage

### Via trait shortcuts

```php
$user = auth()->user(); // User uses HasWallet

// Initiate M-Pesa STK push deposit (returns pending WalletTransaction)
$txn = $user->deposit(1000, '254712345678');

// Initiate B2C withdrawal (locks funds immediately)
$txn = $user->withdraw(500, '254712345678');

// Wallet-to-wallet transfer
[$debit, $credit] = $user->transferTo($anotherUser, 300);

// Check balance (spendable, excludes locked funds)
echo $user->walletBalance()->format(); // "KES 5,000.00"
echo $user->formattedWalletBalance();
```

### Via WalletManager (injected)

```php
use FelixMuhoro\MpesaWallet\WalletManager;

class PaymentService
{
    public function __construct(private WalletManager $wallet) {}

    public function topUp(User $user, int $amount, string $phone): void
    {
        $pendingTxn = $this->wallet->deposit($user, $amount, $phone);
        // CreditWalletOnPayment listener will settle on M-Pesa callback
    }
}
```

---

## API Endpoints

All routes require `auth:sanctum` by default, prefixed `api/wallet`.

| Method | URI                               | Description              |
|--------|-----------------------------------|--------------------------|
| `GET`  | `/api/wallet/balance`             | Current balance          |
| `POST` | `/api/wallet/deposit`             | Initiate STK push        |
| `POST` | `/api/wallet/withdraw`            | Initiate B2C withdrawal  |
| `POST` | `/api/wallet/transfer`            | Wallet-to-wallet transfer|
| `GET`  | `/api/wallet/transactions`        | Paginated history        |
| `GET`  | `/api/wallet/transactions/{uuid}` | Single transaction       |

### Deposit

```json
{ "amount": 1000, "phone": "254712345678" }
```

### Withdraw

```json
{ "amount": 500, "phone": "254712345678" }
```

### Transfer

```json
{ "recipient_id": 42, "amount": 300 }
```

---

## How deposits work

```
User -> POST /deposit -> WalletManager::deposit()
    creates pending WalletTransaction (balance unchanged)
    -> caller fires STK Push via felixmuhoro/laravel-mpesa
Safaricom -> POST /callback -> PaymentSuccessful event
    -> CreditWalletOnPayment listener (queued, 3 retries)
    -> WalletManager::creditWallet() — lockForUpdate -> balance++
    -> pending transaction -> completed
```

## How withdrawals work

```
User -> POST /withdraw -> WalletManager::withdraw()
    lockForUpdate -> locked_balance += amount
    creates pending WalletTransaction
    -> caller fires B2C via felixmuhoro/laravel-mpesa
Safaricom -> POST /callback
  Success -> settleWithdrawal(): balance -= amount, locked -= amount
  Failure -> failWithdrawal():  locked -= amount (funds refunded)
```

---

## Configuration reference

```php
// config/mpesa-wallet.php

return [
    'currency'    => 'KES',
    'auto_create' => true,
    'limits' => [
        'min_deposit'    => 10,
        'max_deposit'    => 150_000,
        'min_withdrawal' => 10,
        'max_withdrawal' => 150_000,
    ],
    'routes' => [
        'enabled'    => true,
        'prefix'     => 'api/wallet',
        'middleware' => ['api', 'auth:sanctum'],
        'name'       => 'mpesa-wallet.',
    ],
    'tables' => [
        'wallets'      => 'wallets',
        'transactions' => 'wallet_transactions',
    ],
];
```

---

## Testing

```bash
composer install
vendor/bin/phpunit --testdox
```

---

## License

MIT
