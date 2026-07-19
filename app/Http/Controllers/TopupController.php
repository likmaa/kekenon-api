<?php

namespace App\Http\Controllers;

use App\Events\PaymentConfirmed;
use App\Models\Ride;
use App\Services\PawaPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TopupController extends Controller
{
    /** Opérateurs Mobile Money supportés (Bénin). */
    private const PROVIDERS = ['MTN_MOMO_BEN', 'MOOV_BEN'];

    public function __construct(protected PawaPayService $pawaPay)
    {
    }

    /**
     * Initie un rechargement de portefeuille via PawaPay (Mobile Money).
     * Le client reçoit une invite de paiement sur son téléphone ; le crédit
     * est appliqué à réception du callback (ou via /status qui relit le dépôt).
     */
    public function initiate(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:200'],
            'phone' => ['required', 'string', 'max:20'],
            'provider' => ['required', 'string', 'in:' . implode(',', self::PROVIDERS)],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $amount = (int) $data['amount'];
        $idemKey = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
        $reference = $idemKey
            ? ('topup_' . $user->id . '_' . substr(sha1($idemKey), 0, 20))
            : ('topup_' . $user->id . '_' . now()->timestamp);

        if ($idemKey) {
            $existing = DB::table('topup_requests')
                ->where('user_id', $user->id)
                ->where('reference', $reference)
                ->whereIn('status', ['pending', 'completed'])
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'reference' => $existing->reference,
                    'deposit_id' => $existing->provider_ref,
                    'status' => $existing->status,
                    'reused' => true,
                ]);
            }
        }

        $depositId = $this->pawaPay->newDepositId();

        try {
            $result = $this->pawaPay->createDeposit([
                'depositId' => $depositId,
                'amount' => $amount,
                'currency' => 'XOF',
                'phoneNumber' => $data['phone'],
                'provider' => $data['provider'],
                'customerMessage' => 'Kekenon',
                'clientReferenceId' => $reference,
                'metadata' => [
                    ['fieldName' => 'type', 'fieldValue' => 'wallet_topup'],
                    ['fieldName' => 'reference', 'fieldValue' => $reference],
                    ['fieldName' => 'user_id', 'fieldValue' => (string) $user->id],
                ],
            ]);

            DB::table('topup_requests')->insert([
                'user_id' => $user->id,
                'reference' => $reference,
                'provider_ref' => $depositId,
                'amount' => $amount,
                'currency' => 'XOF',
                'status' => 'pending',
                'meta' => json_encode(array_merge($result, ['idempotency_key' => $idemKey])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'reference' => $reference,
                'deposit_id' => $depositId,
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            Log::error('PawaPay topup initiation failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return $this->apiError('PAYMENT_INIT_FAILED', $e->getMessage() ?: 'Erreur lors de l\'initiation du paiement.', 502);
        }
    }

    /**
     * Initie le paiement d'une course terminée (Mobile Money) via PawaPay.
     */
    public function initiateRideCheckout(Request $request, int $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'provider' => ['required', 'string', 'in:' . implode(',', self::PROVIDERS)],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $ride = Ride::query()->where('id', $id)->where('rider_id', $user->id)->firstOrFail();

        if ($ride->status !== 'completed') {
            return response()->json(['message' => 'La course n\'est pas terminée.'], 422);
        }

        if ((string) ($ride->payment_method ?? '') !== 'mobile_money') {
            return response()->json(['message' => 'Ce mode de paiement utilise un autre flux.'], 422);
        }

        if ($ride->payment_status === 'completed') {
            return response()->json(['message' => 'Paiement déjà enregistré.'], 409);
        }

        $existingPayment = DB::table('payments')
            ->where('ride_id', $ride->id)
            ->where('status', 'succeeded')
            ->first();
        if ($existingPayment) {
            return response()->json(['message' => 'Paiement déjà enregistré.'], 409);
        }

        $idemKey = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
        $reference = $idemKey
            ? ('ride_pay_' . $ride->id . '_' . substr(sha1($idemKey), 0, 20))
            : ('ride_pay_' . $ride->id . '_' . now()->timestamp);
        $amount = (int) $ride->fare_amount;
        $depositId = $this->pawaPay->newDepositId();

        try {
            $this->pawaPay->createDeposit([
                'depositId' => $depositId,
                'amount' => $amount,
                'currency' => $ride->currency ?? 'XOF',
                'phoneNumber' => $data['phone'],
                'provider' => $data['provider'],
                'customerMessage' => 'Course ' . $ride->id,
                'clientReferenceId' => $reference,
                'metadata' => [
                    ['fieldName' => 'type', 'fieldValue' => 'ride_payment'],
                    ['fieldName' => 'ride_id', 'fieldValue' => (string) $ride->id],
                    ['fieldName' => 'reference', 'fieldValue' => $reference],
                    ['fieldName' => 'user_id', 'fieldValue' => (string) $user->id],
                ],
            ]);

            $ride->external_reference = $depositId;
            $ride->payment_status = 'pending';
            $ride->save();

            return response()->json([
                'ok' => true,
                'reference' => $reference,
                'deposit_id' => $depositId,
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            Log::error('PawaPay ride checkout failed', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);

            return $this->apiError('RIDE_CHECKOUT_INIT_FAILED', $e->getMessage() ?: 'Erreur lors de l\'initiation du paiement.', 502);
        }
    }

    /**
     * Callback PawaPay : notifié quand un dépôt atteint un statut final.
     * On relit le dépôt côté PawaPay (source de vérité) avant tout crédit.
     */
    public function webhook(Request $request)
    {
        $payload = $request->all();
        $depositId = $payload['depositId'] ?? ($payload['data']['depositId'] ?? null);

        if (! $depositId || ! is_string($depositId)) {
            Log::warning('PawaPay callback without depositId', ['payload' => $payload]);

            return response()->json(['ok' => true]);
        }

        // Relecture serveur du statut réel (ne jamais faire confiance au corps seul).
        $deposit = $this->pawaPay->getDeposit($depositId);
        $status = $deposit['status'] ?? ($payload['status'] ?? null);

        Log::info('PawaPay callback received', ['deposit_id' => $depositId, 'status' => $status]);

        if ($status === 'COMPLETED') {
            $ride = Ride::query()->where('external_reference', $depositId)->first();
            if ($ride) {
                $this->completeRideFromDeposit($ride->id, $depositId);
            } else {
                $this->creditWallet($depositId);
            }
        } elseif ($status === 'FAILED' || $status === 'REJECTED') {
            DB::table('topup_requests')
                ->where('provider_ref', $depositId)
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Statut d'un rechargement. Relit PawaPay si encore en attente (secours callback).
     */
    public function status(Request $request, string $reference)
    {
        $user = $request->user();

        $topup = DB::table('topup_requests')
            ->where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $topup) {
            return $this->apiError('TOPUP_NOT_FOUND', 'Not found', 404);
        }

        if ($topup->status === 'pending' && $topup->provider_ref) {
            $deposit = $this->pawaPay->getDeposit((string) $topup->provider_ref);
            $remote = $deposit['status'] ?? null;
            if ($remote === 'COMPLETED') {
                $this->creditWallet((string) $topup->provider_ref);
                $topup = DB::table('topup_requests')->where('id', $topup->id)->first();
            } elseif ($remote === 'FAILED' || $remote === 'REJECTED') {
                DB::table('topup_requests')->where('id', $topup->id)
                    ->update(['status' => 'failed', 'updated_at' => now()]);
                $topup->status = 'failed';
            }
        }

        return response()->json([
            'reference' => $topup->reference,
            'amount' => (int) $topup->amount,
            'status' => $topup->status,
            'created_at' => $topup->created_at,
        ]);
    }

    private function apiError(string $code, string $message, int $status, array $errors = []): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Finalise une course payée via PawaPay.
     */
    protected function completeRideFromDeposit(int $rideId, string $depositId): void
    {
        DB::transaction(function () use ($rideId, $depositId) {
            $ride = Ride::query()->where('id', $rideId)->lockForUpdate()->first();
            if (! $ride) {
                return;
            }

            $existing = DB::table('payments')
                ->where('ride_id', $ride->id)
                ->where('status', 'succeeded')
                ->first();
            if ($existing || $ride->payment_status === 'completed') {
                return;
            }

            $pm = (string) ($ride->payment_method ?? 'mobile_money');
            if (! in_array($pm, ['mobile_money', 'wallet'], true)) {
                $pm = 'mobile_money';
            }

            DB::table('payments')->insert([
                'ride_id' => $ride->id,
                'user_id' => $ride->rider_id,
                'amount' => (int) $ride->fare_amount,
                'currency' => $ride->currency ?? 'XOF',
                'method' => $pm,
                'status' => 'succeeded',
                'meta' => json_encode(['pawapay_deposit_id' => $depositId]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ride->payment_status = 'completed';
            $ride->save();

            if ($ride->driver_id && (int) $ride->driver_earnings_amount > 0) {
                $driverWallet = DB::table('wallets')
                    ->where('user_id', $ride->driver_id)
                    ->lockForUpdate()
                    ->first();
                if (! $driverWallet) {
                    $dWid = DB::table('wallets')->insertGetId([
                        'user_id' => $ride->driver_id,
                        'balance' => 0,
                        'currency' => $ride->currency ?? 'XOF',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $driverWallet = (object) ['id' => $dWid, 'balance' => 0];
                }

                $earnings = (int) $ride->driver_earnings_amount;
                $dBefore = (int) $driverWallet->balance;
                $dAfter = $dBefore + $earnings;

                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $driverWallet->id,
                    'type' => 'credit',
                    'source' => $ride->service_type === 'livraison' ? 'delivery_earnings' : 'ride_earnings',
                    'amount' => $earnings,
                    'balance_before' => $dBefore,
                    'balance_after' => $dAfter,
                    'meta' => json_encode(['ride_id' => $ride->id, 'via' => 'pawapay']),
                    'created_at' => now(),
                ]);

                DB::table('wallets')->where('id', $driverWallet->id)->update([
                    'balance' => $dAfter,
                    'updated_at' => now(),
                ]);
            }
        });

        $fresh = Ride::find($rideId);
        if ($fresh && $fresh->payment_status === 'completed') {
            rescue(fn () => broadcast(new PaymentConfirmed($fresh)));
        }
    }

    /**
     * Crédite le portefeuille après un rechargement PawaPay confirmé.
     */
    protected function creditWallet(string $depositId): void
    {
        DB::transaction(function () use ($depositId) {
            $topup = DB::table('topup_requests')
                ->where('provider_ref', $depositId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $topup) {
                return; // Déjà traité ou introuvable.
            }

            $wallet = DB::table('wallets')
                ->where('user_id', $topup->user_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $walletId = DB::table('wallets')->insertGetId([
                    'user_id' => $topup->user_id,
                    'balance' => 0,
                    'currency' => 'XOF',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $wallet = (object) ['id' => $walletId, 'balance' => 0];
            }

            $before = (int) $wallet->balance;
            $after = $before + (int) $topup->amount;

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'source' => 'topup_pawapay',
                'amount' => (int) $topup->amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => json_encode([
                    'reference' => $topup->reference,
                    'pawapay_deposit_id' => $depositId,
                ]),
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            DB::table('topup_requests')
                ->where('id', $topup->id)
                ->update(['status' => 'completed', 'updated_at' => now()]);

            Log::info('PawaPay topup credited', [
                'user_id' => $topup->user_id,
                'amount' => $topup->amount,
                'reference' => $topup->reference,
            ]);
        });
    }
}
