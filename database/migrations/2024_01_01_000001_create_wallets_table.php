<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('mpesa-wallet.tables.wallets', 'wallets');

        Schema::create($table, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Polymorphic owner (User, Merchant, etc.)
            $table->morphs('owner');

            // Currency: ISO 4217, e.g. KES
            $table->char('currency', 3)->default('KES');

            // Balances stored as integers (whole KES units).
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('locked_balance')->default(0)
                ->comment('Funds reserved for pending outgoing transactions');

            $table->boolean('is_frozen')->default(false)
                ->comment('Frozen wallets reject all credit/debit operations');

            $table->timestamps();
            $table->softDeletes();

            // One wallet per owner per currency.
            $table->unique(['owner_type', 'owner_id', 'currency'], 'wallets_owner_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mpesa-wallet.tables.wallets', 'wallets'));
    }
};
