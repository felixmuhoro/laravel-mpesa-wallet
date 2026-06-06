<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet;

use FelixMuhoro\MpesaWallet\Listeners\CreditWalletOnPayment;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MpesaWalletServiceProvider extends ServiceProvider
{
    private const EVENT_LISTENERS = [
        // Event fired by felixmuhoro/laravel-mpesa after a successful STK Push callback.
        'FelixMuhoro\\Mpesa\\Events\\PaymentSuccessful' => [
            CreditWalletOnPayment::class,
        ],
    ];

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mpesa-wallet.php',
            'mpesa-wallet',
        );

        $this->app->singleton(WalletManager::class, static function ($app): WalletManager {
            return new WalletManager(
                db: $app->make(DatabaseManager::class),
                config: $app->make(Config::class),
            );
        });

        $this->app->alias(WalletManager::class, 'mpesa-wallet');
    }

    // -------------------------------------------------------------------------
    // Booting
    // -------------------------------------------------------------------------

    public function boot(): void
    {
        $this->publishAssets();
        $this->loadMigrations();
        $this->registerEventListeners();
        $this->registerRoutes();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function publishAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/mpesa-wallet.php' => config_path('mpesa-wallet.php'),
        ], 'mpesa-wallet-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'mpesa-wallet-migrations');
    }

    private function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function registerEventListeners(): void
    {
        foreach (self::EVENT_LISTENERS as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    private function registerRoutes(): void
    {
        if (! config('mpesa-wallet.routes.enabled', true)) {
            return;
        }

        Route::group($this->routeConfig(), function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        });
    }

    private function routeConfig(): array
    {
        return [
            'prefix'     => config('mpesa-wallet.routes.prefix', 'api/wallet'),
            'middleware' => config('mpesa-wallet.routes.middleware', ['api', 'auth:sanctum']),
            'as'         => config('mpesa-wallet.routes.name', 'mpesa-wallet.'),
        ];
    }
}
