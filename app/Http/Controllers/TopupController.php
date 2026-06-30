<?php

namespace App\Http\Controllers;

use App\Events\PaymentConfirmed;
use App\Models\Ride;
use App\Services\GeniusPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TopupController extends Controller
{
    protected GeniusPayService $geniusPay;

    public function __construct(GeniusPayService $geniusPay)
    {
        $this->geniusPay = $geniusPay;
    }

    /**
     * Initiate a wallet top-up via GeniusPay.
     * Returns a checkout URL the mobile app should open in a browser/webview.
     */
    public function initiate(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:200'],
            'payment_method' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
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
                $meta = json_decode((string) ($existing->meta ?? '{}'), true);
                return response()->json([
                    'ok' => true,
                    'reference' => $existing->reference,
                    'payment_url' => $meta['payment_url'] ?? null,
                    'payment_id' => $existing->geniuspay_id,
                    'reused' => true,
                ]);
            }
        }

        try {
            $params = [
                'amount' => $amount,
                'currency' => 'XOF',
                'description' => "Rechargement wallet Kêkênon - {$amount} XOF",
                'customer' => [
                    'name' => $user->name,
                    'phone' => $data['phone'] ?? $user->phone,
                    'email' => $user->email,
                    'country' => 'BJ',
                ],
                'metadata' => [
                    'user_id' => $user->id,
                    'reference' => $reference,
                    'type' => 'wallet_topup',
                ],
                'success_url' => config('app.url') . '/api/topup/success?ref=' . $reference,
                'error_url' => config('app.url') . '/api/topup/error?ref=' . $reference,
            ];

            if (!empty($data['payment_method'])) {
                $params['payment_method'] = $data['payment_method'];
            }

            $result = $this->geniusPay->createPayment($params);

            DB::table('topup_requests')->insert([
                'user_id' => $user->id,
                'reference' => $reference,
                'geniuspay_id' => $result['id'] ?? $result['payment_id'] ?? null,
                'amount' => $amount,
                'currency' => 'XOF',
                'status' => 'pending',
                'meta' => json_encode(array_merge($result, [
                    'idempotency_key' => $idemKey,
                    'payment_url' => $result['payment_url'] ?? $result['checkout_url'] ?? null,
                ])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'reference' => $reference,
                'payment_url' => $result['payment_url'] ?? $result['checkout_url'] ?? null,
                'payment_id' => $result['id'] ?? $result['payment_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('GeniusPay topup initiation failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return $this->apiError('PAYMENT_INIT_FAILED', 'Erreur lors de l\'initiation du paiement.', 500);
        }
    }

    /**
     * Initie le paiement d’une course terminée via GeniusPay (Mobile Money, carte, etc.).
     */
    public function initiateRideCheckout(Request $request, int $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $ride = Ride::query()->where('id', $id)->where('rider_id', $user->id)->firstOrFail();

        if ($ride->status !== 'completed') {
            return response()->json(['message' => 'La course n’est pas terminée.'], 422);
        }

        $pm = (string) ($ride->payment_method ?? '');
        if (!in_array($pm, ['mobile_money', 'card', 'qr'], true)) {
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

        if (!empty($ride->payment_link) && $ride->payment_status === 'pending') {
            return response()->json([
                'ok' => true,
                'payment_url' => $ride->payment_link,
                'reused' => true,
            ]);
        }

        $idemKey = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
        $reference = $idemKey
            ? ('ride_pay_' . $ride->id . '_' . substr(sha1($idemKey), 0, 20))
            : ('ride_pay_' . $ride->id . '_' . now()->timestamp);
        $amount = (int) $ride->fare_amount;

        try {
            $params = [
                'amount' => $amount,
                'currency' => $ride->currency ?? 'XOF',
                'description' => 'Paiement course Kêkênon #' . $ride->id,
                'customer' => [
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'country' => 'BJ',
                ],
                'metadata' => [
                    'type' => 'ride_payment',
                    'ride_id' => $ride->id,
                    'reference' => $reference,
                    'user_id' => $user->id,
                ],
                'success_url' => rtrim((string) config('app.url'), '/') . '/api/ride-payment/success?ride_id=' . $ride->id,
                'error_url' => rtrim((string) config('app.url'), '/') . '/api/ride-payment/cancel?ride_id=' . $ride->id,
            ];

            if ($pm === 'card') {
                $params['payment_method'] = 'card';
            }

            $result = $this->geniusPay->createPayment($params);

            $payUrl = $result['payment_url'] ?? $result['checkout_url'] ?? null;
            if (!$payUrl || !is_string($payUrl)) {
                throw new \RuntimeException('GeniusPay : aucune URL de paiement dans la réponse.');
            }

            $ride->payment_link = $payUrl;
            $ride->external_reference = (string) ($result['reference'] ?? $result['id'] ?? $reference);
            $ride->save();

            return response()->json([
                'ok' => true,
                'reference' => $reference,
                'payment_url' => $payUrl,
                'payment_id' => $result['id'] ?? $result['payment_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('GeniusPay ride checkout failed', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);

            return $this->apiError('RIDE_CHECKOUT_INIT_FAILED', 'Erreur lors de l’ouverture du paiement.', 500);
        }
    }

    /**
     * Webhook called by GeniusPay when payment status changes.
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-GeniusPay-Signature', '');

        if (!$this->geniusPay->verifyWebhookSignature($payload, $signature)) {
            Log::warning('GeniusPay webhook signature mismatch');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = $request->all();
        $status = $data['status'] ?? null;
        $paymentId = $data['id'] ?? $data['payment_id'] ?? null;
        $metadata = $data['metadata'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        Log::info('GeniusPay webhook received', [
            'payment_id' => $paymentId,
            'status' => $status,
            'metadata_type' => $metadata['type'] ?? null,
        ]);

        if ($status === 'completed' || $status === 'success' || $status === 'successful') {
            if (($metadata['type'] ?? '') === 'ride_payment') {
                $this->completeRideFromGeniusPay($paymentId !== null ? (string) $paymentId : null, $metadata);
            } else {
                $this->creditWallet($paymentId, $metadata, $data);
            }
        } else {
            DB::table('topup_requests')
                ->where('geniuspay_id', $paymentId)
                ->update([
                    'status' => $status ?? 'failed',
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Success redirect (mobile deep link can be triggered here).
     */
    public function success(Request $request)
    {
        $ref = $request->query('ref');
        return response()->json([
            'ok' => true,
            'message' => 'Rechargement effectué avec succès.',
            'reference' => $ref,
        ]);
    }

    /**
     * Error redirect.
     */
    public function error(Request $request)
    {
        $ref = $request->query('ref');
        return response()->json([
            'ok' => false,
            'message' => 'Le rechargement a échoué.',
            'reference' => $ref,
        ]);
    }

    /**
     * Check the status of a topup request.
     */
    public function status(Request $request, string $reference)
    {
        $user = $request->user();

        $topup = DB::table('topup_requests')
            ->where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (!$topup) {
            return $this->apiError('TOPUP_NOT_FOUND', 'Not found', 404);
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
     * Finalise une course payée via GeniusPay (webhook).
     */
    protected function completeRideFromGeniusPay(?string $paymentId, array $metadata): void
    {
        $rideId = isset($metadata['ride_id']) ? (int) $metadata['ride_id'] : 0;
        if ($rideId <= 0) {
            Log::warning('GeniusPay ride webhook: missing ride_id', ['metadata' => $metadata]);

            return;
        }

        DB::transaction(function () use ($rideId, $paymentId, $metadata) {
            $ride = Ride::query()->where('id', $rideId)->lockForUpdate()->first();
            if (!$ride) {
                Log::warning('GeniusPay ride webhook: ride not found', ['ride_id' => $rideId]);

                return;
            }

            $existing = DB::table('payments')
                ->where('ride_id', $ride->id)
                ->where('status', 'succeeded')
                ->first();
            if ($existing) {
                return;
            }

            if ($ride->payment_status === 'completed') {
                return;
            }

            $pm = (string) ($ride->payment_method ?? 'mobile_money');
            if (!in_array($pm, ['mobile_money', 'card', 'qr', 'wallet'], true)) {
                $pm = 'mobile_money';
            }

            DB::table('payments')->insert([
                'ride_id' => $ride->id,
                'user_id' => $ride->rider_id,
                'amount' => (int) $ride->fare_amount,
                'currency' => $ride->currency ?? 'XOF',
                'method' => $pm,
                'status' => 'succeeded',
                'meta' => json_encode([
                    'geniuspay_id' => $paymentId,
                    'reference' => $metadata['reference'] ?? null,
                ]),
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
                if (!$driverWallet) {
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
                    'source' => 'ride_earnings',
                    'amount' => $earnings,
                    'balance_before' => $dBefore,
                    'balance_after' => $dAfter,
                    'meta' => json_encode(['ride_id' => $ride->id, 'via' => 'geniuspay']),
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
            broadcast(new PaymentConfirmed($fresh));
        }
    }

    protected function creditWallet(?string $paymentId, array $metadata, array $rawData): void
    {
        $reference = $metadata['reference'] ?? null;

        if (!$paymentId && !$reference) {
            Log::warning('GeniusPay webhook: missing payment id and reference');

            return;
        }

        DB::transaction(function () use ($paymentId, $reference) {
            $q = DB::table('topup_requests')->where('status', 'pending');
            if ($paymentId && $reference) {
                $q->where(function ($sub) use ($paymentId, $reference) {
                    $sub->where('geniuspay_id', $paymentId)->orWhere('reference', $reference);
                });
            } elseif ($paymentId) {
                $q->where('geniuspay_id', $paymentId);
            } else {
                $q->where('reference', $reference);
            }

            $topup = $q->lockForUpdate()->first();

            if (!$topup) {
                Log::warning('GeniusPay webhook: no pending topup found', [
                    'payment_id' => $paymentId,
                    'reference' => $reference,
                ]);

                return;
            }

            $wallet = DB::table('wallets')
                ->where('user_id', $topup->user_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
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
                'source' => 'topup_geniuspay',
                'amount' => (int) $topup->amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => json_encode([
                    'reference' => $topup->reference,
                    'geniuspay_id' => $topup->geniuspay_id,
                ]),
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            DB::table('topup_requests')
                ->where('id', $topup->id)
                ->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);

            Log::info('GeniusPay topup credited', [
                'user_id' => $topup->user_id,
                'amount' => $topup->amount,
                'reference' => $topup->reference,
            ]);
        });
    }
}
