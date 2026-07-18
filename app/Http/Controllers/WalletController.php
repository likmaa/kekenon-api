<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Events\PaymentConfirmed;
use App\Services\PassengerBonusService;

class WalletController extends Controller
{
    public function __construct(private readonly PassengerBonusService $passengerBonusService)
    {
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

    protected function getOrCreateWallet(int $userId, bool $lock = false): array
    {
        $query = DB::table('wallets')->where('user_id', $userId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $wallet = $query->first();
        if (!$wallet) {
            DB::table('wallets')->insert([
                'user_id' => $userId,
                'balance' => 0,
                'currency' => 'XOF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $query = DB::table('wallets')->where('user_id', $userId);
            if ($lock) {
                $query->lockForUpdate();
            }
            $wallet = $query->first();
        }
        return (array) $wallet;
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $wallet = $this->getOrCreateWallet($user->id);

        return response()->json([
            'balance' => (int) $wallet['balance'],
            'bonus_balance' => (int) ($wallet['bonus_balance'] ?? 0),
            'ride_bonus_balance' => $this->passengerBonusService->availableAmount($user),
            'currency' => $wallet['currency'],
        ]);
    }

    public function todayTransactions(Request $request)
    {
        $user = $request->user();
        $wallet = $this->getOrCreateWallet($user->id);

        $today = now()->toDateString();

        $rows = DB::table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->whereDate('created_at', $today)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $transactions = $rows->map(function ($row) {
            $time = $row->created_at ? date('H:i', strtotime((string) $row->created_at)) : null;

            return [
                'id' => $row->id,
                'type' => $row->type,       // credit | debit
                'source' => $row->source,
                'label' => $this->transactionLabel((string) $row->source),
                'amount' => (int) $row->amount,
                'time' => $time,
            ];
        });

        return response()->json([
            'wallet_id' => $wallet['id'],
            'date' => $today,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Historique paginé des transactions portefeuille (passager).
     */
    public function transactionsHistory(Request $request)
    {
        $user = $request->user();
        $wallet = $this->getOrCreateWallet($user->id);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 80);

        $paginator = DB::table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = collect($paginator->items())->map(function ($row) {
            $source = (string) $row->source;
            $ledger = (string) $row->type;
            $amount = (int) $row->amount;

            return [
                'id' => (int) $row->id,
                'type' => $this->transactionMobileCategory($source),
                'description' => $this->transactionLabel($source),
                'amount' => $ledger === 'credit' ? $amount : -$amount,
                'date' => $row->created_at
                    ? Carbon::parse((string) $row->created_at)->toIso8601String()
                    : now()->toIso8601String(),
                'source' => $source,
                'ledger_type' => $ledger,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function transactionLabel(string $source): string
    {
        return match ($source) {
            'ride_payment' => 'Paiement course',
            'ride_earnings' => 'Gain course',
            'topup_cash' => 'Rechargement (espèces)',
            'topup_qr' => 'Rechargement (QR)',
            'topup_pawapay' => 'Rechargement (Mobile Money)',
            'topup_geniuspay' => 'Rechargement', // héritage : anciennes transactions
            'ride_tip' => 'Pourboire',
            'commission_deduction' => 'Commission',
            'subscription_fee' => 'Abonnement (10 courses)',
            'subscription_fee_bonus' => 'Abonnement (payé en bonus)',
            'bonus_grant' => 'Bonus offert',
            'app_fee' => 'Frais de plateforme',
            'withdrawal' => 'Retrait',
            'admin_reset' => 'Ajustement solde',
            'admin_adjustment' => 'Ajustement',
            default => ucfirst(str_replace('_', ' ', $source)),
        };
    }

    private function transactionMobileCategory(string $source): string
    {
        if (str_starts_with($source, 'topup')) {
            return 'topup';
        }
        if ($source === 'withdrawal') {
            return 'withdrawal';
        }
        if (str_contains($source, 'bonus') || str_starts_with($source, 'subscription')) {
            return 'bonus';
        }
        if (str_contains($source, 'delivery')) {
            return 'delivery';
        }

        return 'ride';
    }

    public function topup(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,qr'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $idemKey = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
        if ($idemKey) {
            $existing = DB::table('wallet_transactions')
                ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
                ->where('wallets.user_id', $user->id)
                ->where('wallet_transactions.source', $data['method'] === 'cash' ? 'topup_cash' : 'topup_qr')
                ->where('wallet_transactions.created_at', '>=', now()->subMinutes(5))
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(wallet_transactions.meta, '$.idempotency_key')) = ?", [$idemKey])
                ->orderByDesc('wallet_transactions.id')
                ->select('wallet_transactions.id')
                ->first();

            if ($existing) {
                $wallet = $this->getOrCreateWallet($user->id);
                return response()->json([
                    'ok' => true,
                    'reused' => true,
                    'balance' => (int) $wallet['balance'],
                    'currency' => $wallet['currency'],
                ]);
            }
        }

        $wallet = null;

        DB::transaction(function () use ($user, $data, &$wallet) {
            $wallet = $this->getOrCreateWallet($user->id, true);
            $before = (int) $wallet['balance'];
            $after = $before + (int) $data['amount'];

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet['id'],
                'type' => 'credit',
                'source' => $data['method'] === 'cash' ? 'topup_cash' : 'topup_qr',
                'amount' => (int) $data['amount'],
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => json_encode([
                    'idempotency_key' => $idemKey,
                ]),
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $wallet['id'])->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            $wallet['balance'] = $after;
        });

        return response()->json([
            'ok' => true,
            'balance' => (int) $wallet['balance'],
            'currency' => 'XOF',
        ]);
    }

    public function payRide(Request $request, int $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'method' => ['required', 'in:cash,wallet,qr,mobile_money,card'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $idemKey = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;

        if ($idemKey !== null && $idemKey !== '') {
            $existingByIdem = DB::table('payments')
                ->where('ride_id', $id)
                ->where('user_id', $user->id)
                ->where('status', 'succeeded')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payments.meta, '$.idempotency_key')) = ?", [$idemKey])
                ->orderByDesc('id')
                ->first();

            if ($existingByIdem) {
                return response()->json([
                    'ok' => true,
                    'reused' => true,
                    'payment_id' => (int) $existingByIdem->id,
                    'ride_id' => (int) $id,
                    'method' => $data['method'],
                    'status' => 'succeeded',
                ]);
            }
        }

        $result = null;

        $ride = null;

        DB::transaction(function () use ($user, $data, $id, $idemKey, &$result, &$ride) {
            $ride = Ride::where('id', $id)->where('rider_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($ride->status !== 'completed') {
                abort($this->apiError('RIDE_NOT_COMPLETED', 'Ride not completed', 422));
            }

            $existing = DB::table('payments')
                ->where('ride_id', $ride->id)
                ->where('status', 'succeeded')
                ->first();
            if ($existing) {
                abort($this->apiError('RIDE_ALREADY_PAID', 'Ride already paid', 409));
            }

            $amount = (int) $ride->fare_amount;
            $currency = $ride->currency ?? 'XOF';
            $status = 'succeeded';

            if ($data['method'] === 'wallet') {
                $wallet = $this->getOrCreateWallet($user->id, true);
                $before = (int) $wallet['balance'];

                // SEC-WALLET-01 : Bloquer strictement le paiement si le solde est insuffisant.
                // L'ancienne logique min($before, $amount) permettait à un passager avec 0 FCFA
                // de voyager gratuitement pendant que le système payait le chauffeur de sa poche.
                if ($before < $amount) {
                    abort($this->apiError(
                        'INSUFFICIENT_FUNDS',
                        'Solde insuffisant. Votre solde est de ' . $before . ' XOF, le montant à payer est de ' . $amount . ' XOF.',
                        422
                    ));
                }

                // Débit de la totalité du montant — le reste en espèces est donc toujours 0.
                $walletDebit = $amount;
                $cashRemainder = 0;
                $after = $before - $walletDebit;

                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $wallet['id'],
                    'type' => 'debit',
                    'source' => 'ride_payment',
                    'amount' => $walletDebit,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'meta' => json_encode([
                        'ride_id' => $ride->id,
                        'cash_remainder' => $cashRemainder,
                        'idempotency_key' => $idemKey,
                    ]),
                    'created_at' => now(),
                ]);

                DB::table('wallets')->where('id', $wallet['id'])->update([
                    'balance' => $after,
                    'updated_at' => now(),
                ]);

                // Créditer le chauffeur uniquement après le débit réel du passager.
                // On s'assure que l'argent a bien été prélevé avant tout versement.
                if ($ride->driver_id) {
                    $earnings = (int) $ride->driver_earnings_amount;
                    if ($earnings > 0) {
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

                        $dBefore = (int) $driverWallet->balance;
                        $dAfter = $dBefore + $earnings;

                        DB::table('wallet_transactions')->insert([
                            'wallet_id' => $driverWallet->id,
                            'type' => 'credit',
                            'source' => 'ride_earnings',
                            'amount' => $earnings,
                            'balance_before' => $dBefore,
                            'balance_after' => $dAfter,
                            'meta' => json_encode(['ride_id' => $ride->id]),
                            'created_at' => now(),
                        ]);

                        DB::table('wallets')->where('id', $driverWallet->id)->update([
                            'balance' => $dAfter,
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $paymentId = DB::table('payments')->insertGetId([
                'ride_id' => $ride->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'currency' => $currency,
                'method' => $data['method'],
                'status' => $status,
                'meta' => json_encode([
                    'wallet_debit' => $walletDebit ?? 0,
                    'cash_remainder' => $cashRemainder ?? 0,
                    'idempotency_key' => $idemKey,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ride->payment_status = 'completed';
            $ride->save();

            $result = [
                'ok' => true,
                'payment_id' => $paymentId,
                'ride_id' => $ride->id,
                'amount' => $amount,
                'currency' => $currency,
                'method' => $data['method'],
                'status' => $status,
                'wallet_debited' => $walletDebit ?? 0,
                'cash_remainder' => $cashRemainder ?? 0,
            ];
        });

        if ($ride) {
            rescue(fn () => broadcast(new PaymentConfirmed($ride)));
        }

        return response()->json($result);
    }

    public function adminReset(Request $request, int $userId)
    {
        // Réservé à un usage admin (route protégée côté routes/api.php)

        $result = null;

        DB::transaction(function () use ($userId, &$result) {
            $wallet = $this->getOrCreateWallet($userId, true);
            $before = (int) $wallet['balance'];

            if ($before === 0) {
                $result = [
                    'ok' => true,
                    'balance_before' => 0,
                    'balance_after' => 0,
                    'currency' => $wallet['currency'],
                    'message' => 'Wallet déjà à 0.',
                ];
                return;
            }

            $after = 0;
            $amount = abs($before); // montant de l’ajustement

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet['id'],
                'type' => $before > 0 ? 'debit' : 'credit',
                'source' => 'admin_reset',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => null,
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $wallet['id'])->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            $result = [
                'ok' => true,
                'balance_before' => $before,
                'balance_after' => $after,
                'currency' => $wallet['currency'],
            ];
        });

        return response()->json($result);
    }
}
