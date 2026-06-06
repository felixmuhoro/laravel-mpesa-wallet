<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWallet\Http\Controllers;

use FelixMuhoro\MpesaWallet\Exceptions\InsufficientBalanceException;
use FelixMuhoro\MpesaWallet\Exceptions\TransactionLimitException;
use FelixMuhoro\MpesaWallet\Exceptions\WalletFrozenException;
use FelixMuhoro\MpesaWallet\Http\Resources\WalletResource;
use FelixMuhoro\MpesaWallet\Http\Resources\WalletTransactionResource;
use FelixMuhoro\MpesaWallet\WalletManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * REST API surface for wallet operations.
 * All routes are authenticated (see config/mpesa-wallet.php routes.middleware).
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletManager $walletManager,
    ) {}

    // -------------------------------------------------------------------------
    // GET /wallet/balance
    // -------------------------------------------------------------------------

    public function balance(Request $request): WalletResource
    {
        $wallet = $this->walletManager->findOrCreateWallet($request->user());

        return new WalletResource($wallet);
    }

    // -------------------------------------------------------------------------
    // POST /wallet/deposit
    // -------------------------------------------------------------------------

    /**
     * Initiate an M-Pesa STK push to top up the authenticated user's wallet.
     *
     * Body: { "amount": int, "phone": "254..." }
     */
    public function deposit(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'amount' => ['required', 'integer', 'min:10'],
            'phone'  => ['required', 'string', 'regex:/^254[0-9]{9}$/'],
        ]);

        try {
            $transaction = $this->walletManager->deposit(
                owner: $request->user(),
                amount: (int) $data['amount'],
                phone: $data['phone'],
            );
        } catch (TransactionLimitException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (WalletFrozenException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        }

        return (new WalletTransactionResource($transaction))
            ->response()
            ->setStatusCode(202);
    }

    // -------------------------------------------------------------------------
    // POST /wallet/withdraw
    // -------------------------------------------------------------------------

    /**
     * Lock funds and send an M-Pesa B2C payment.
     *
     * Body: { "amount": int, "phone": "254..." }
     */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'amount' => ['required', 'integer', 'min:10'],
            'phone'  => ['required', 'string', 'regex:/^254[0-9]{9}$/'],
        ]);

        try {
            $transaction = $this->walletManager->withdraw(
                owner: $request->user(),
                amount: (int) $data['amount'],
                phone: $data['phone'],
            );
        } catch (InsufficientBalanceException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (WalletFrozenException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        } catch (TransactionLimitException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return (new WalletTransactionResource($transaction))
            ->response()
            ->setStatusCode(202);
    }

    // -------------------------------------------------------------------------
    // POST /wallet/transfer
    // -------------------------------------------------------------------------

    /**
     * Transfer funds from the authenticated user to another owner.
     *
     * Body: { "recipient_id": int, "amount": int }
     */
    public function transfer(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'recipient_id' => ['required', 'integer'],
            'amount'       => ['required', 'integer', 'min:1'],
        ]);

        $recipientClass = get_class($request->user());
        $recipient      = $recipientClass::findOrFail($data['recipient_id']);

        try {
            [$debit] = $this->walletManager->transfer(
                from: $request->user(),
                to: $recipient,
                amount: (int) $data['amount'],
            );
        } catch (InsufficientBalanceException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (WalletFrozenException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        }

        return (new WalletTransactionResource($debit))
            ->response()
            ->setStatusCode(201);
    }

    // -------------------------------------------------------------------------
    // GET /wallet/transactions
    // -------------------------------------------------------------------------

    public function transactions(Request $request): AnonymousResourceCollection
    {
        $wallet = $this->walletManager->findOrCreateWallet($request->user());

        $transactions = $wallet->transactions()
            ->latest()
            ->paginate(min((int) ($request->query('per_page', 20)), 100));

        return WalletTransactionResource::collection($transactions);
    }

    // -------------------------------------------------------------------------
    // GET /wallet/transactions/{uuid}
    // -------------------------------------------------------------------------

    public function showTransaction(Request $request, string $uuid): WalletTransactionResource
    {
        $wallet = $this->walletManager->findOrCreateWallet($request->user());

        $transaction = $wallet->transactions()
            ->where('uuid', $uuid)
            ->firstOrFail();

        return new WalletTransactionResource($transaction);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @throws ValidationException */
    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
