<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('MPESA_WALLET_CURRENCY', 'KES'),

    /*
    |--------------------------------------------------------------------------
    | Wallet Limits
    |--------------------------------------------------------------------------
    | Minimum and maximum single-transaction amounts (in the default currency).
    | These mirror the CBK-mandated M-Pesa limits for tier-1 accounts.
    */
    'limits' => [
        'min_deposit'    => (int) env('MPESA_WALLET_MIN_DEPOSIT', 10),
        'max_deposit'    => (int) env('MPESA_WALLET_MAX_DEPOSIT', 150_000),
        'min_withdrawal' => (int) env('MPESA_WALLET_MIN_WITHDRAWAL', 10),
        'max_withdrawal' => (int) env('MPESA_WALLET_MAX_WITHDRAWAL', 150_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled'    => (bool) env('MPESA_WALLET_ROUTES_ENABLED', true),
        'prefix'     => env('MPESA_WALLET_ROUTE_PREFIX', 'api/wallet'),
        'middleware' => ['api', 'auth:sanctum'],
        'name'       => 'mpesa-wallet.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */
    'tables' => [
        'wallets'      => env('MPESA_WALLET_TABLE', 'wallets'),
        'transactions' => env('MPESA_WALLET_TRANSACTIONS_TABLE', 'wallet_transactions'),
    ],

    /*
    |--------------------------------------------------------------------------
    | STK Push Callback URL
    |--------------------------------------------------------------------------
    */
    'callback_url' => env('MPESA_WALLET_CALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Auto-create Wallet
    |--------------------------------------------------------------------------
    | When true, the HasWallet trait will automatically provision a wallet for
    | the owner model the first time wallet() is accessed.
    */
    'auto_create' => (bool) env('MPESA_WALLET_AUTO_CREATE', true),

];
