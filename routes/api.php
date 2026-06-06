<?php

declare(strict_types=1);

use FelixMuhoro\MpesaWallet\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M-Pesa Wallet API Routes
|--------------------------------------------------------------------------
| Registered automatically by MpesaWalletServiceProvider.
| Prefix and middleware driven by config('mpesa-wallet.routes').
*/

Route::get('/balance', [WalletController::class, 'balance'])
    ->name('balance');

Route::get('/transactions', [WalletController::class, 'transactions'])
    ->name('transactions.index');

Route::get('/transactions/{uuid}', [WalletController::class, 'showTransaction'])
    ->name('transactions.show')
    ->where('uuid', '[0-9a-f-]{36}');

Route::post('/deposit', [WalletController::class, 'deposit'])
    ->name('deposit');

Route::post('/withdraw', [WalletController::class, 'withdraw'])
    ->name('withdraw');

Route::post('/transfer', [WalletController::class, 'transfer'])
    ->name('transfer');
