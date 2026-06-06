<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $walletsTable = config('mpesa-wallet.tables.wallets', 'wallets');
        $txTable      = config('mpesa-wallet.tables.transactions', 'wallet_transactions');

        Schema::create($txTable, static function (Blueprint $table) use ($walletsTable): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('wallet_id')
                ->constrained($walletsTable)
                ->cascadeOnDelete();

            // credit | debit
            $table->string('type', 10);

            // Amount in whole currency units (KES)
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('KES');

            // pending | completed | failed
            $table->string('status', 12)->default('pending');

            // Correlation ID: M-Pesa CheckoutRequestID, B2C ConversationID, etc.
            $table->string('reference')->index();

            // Arbitrary JSON metadata (phone, mpesa reference, transfer_to, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();

            // Lookup by wallet + status is the most common access pattern.
            $table->index(['wallet_id', 'status']);

            // Prevent processing the same M-Pesa callback twice.
            $table->unique(['wallet_id', 'reference'], 'wallet_txns_wallet_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mpesa-wallet.tables.transactions', 'wallet_transactions'));
    }
};
