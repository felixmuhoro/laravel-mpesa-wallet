<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Listeners;

use FelixMuhoro\MpesaWallet\Enums\TransactionStatus;
use FelixMuhoro\MpesaWallet\Models\WalletTransaction;
use FelixMuhoro\MpesaWallet\ValueObjects\Money;
use FelixMuhoro\MpesaWallet\WalletManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;

/**
 * Listens for the PaymentSuccessful event emitted by felixmuhoro/laravel-mpesa
 * and credits the matching pending wallet transaction.
 *
 * Expected event shape (duck-typed):
 *   $event->checkoutRequestId  string  correlates to WalletTransaction.reference
 *   $event->amount             int|float
 *   $event->phone              string  subscriber MSISDN
 *   $event->resultCode         int     0 = success
 */
class CreditWalletOnPayment implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries  = 3;
    public int $backoff = 30;

    public function __construct(
        private readonly WalletManager $walletManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(object $event): void
    {
        if ((int) ($event->resultCode ?? -1) !== 0) {
            $this->logger->info('[mpesa-wallet] Ignoring non-zero STK result.', [
                'resultCode'        => $event->resultCode ?? null,
                'checkoutRequestId' => $event->checkoutRequestId ?? null,
            ]);

            return;
        }

        $reference = $event->checkoutRequestId ?? null;

        if (empty($reference)) {
            $this->logger->warning('[mpesa-wallet] PaymentSuccessful event missing checkoutRequestId.');

            return;
        }

        /** @var WalletTransaction|null $transaction */
        $transaction = WalletTransaction::query()
            ->byReference($reference)
            ->pending()
            ->first();

        if ($transaction === null) {
            $this->logger->info('[mpesa-wallet] No pending transaction for reference; skipping.', [
                'reference' => $reference,
            ]);

            return;
        }

        $confirmedAmount = (int) round((float) ($event->amount ?? $transaction->amount));
        $currency        = $transaction->currency ?? config('mpesa-wallet.currency', 'KES');
        $money           = Money::of($confirmedAmount, $currency);

        $this->walletManager->creditWallet(
            wallet: $transaction->wallet,
            money: $money,
            meta: [
                'mpesa_reference'  => $reference,
                'phone'            => $event->phone ?? null,
                'confirmed_amount' => $confirmedAmount,
            ],
            reference: $reference,
        );

        $transaction->update([
            'status' => TransactionStatus::Completed->value,
            'amount' => $confirmedAmount,
        ]);

        $this->logger->info('[mpesa-wallet] Wallet credited.', [
            'wallet_id' => $transaction->wallet_id,
            'amount'    => $confirmedAmount,
            'currency'  => $currency,
            'reference' => $reference,
        ]);
    }

    public function failed(object $event, \Throwable $exception): void
    {
        $this->logger->error('[mpesa-wallet] CreditWalletOnPayment failed permanently.', [
            'reference' => $event->checkoutRequestId ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}
