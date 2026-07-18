<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    protected function apiError(string $code, string $message, int $status): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $code, 'message' => $message], $status);
    }

    /** Prix de l'abonnement (10 courses). */
    private const SUBSCRIPTION_PRICE = 500;

    /**
     * POST /driver/subscription/renew
     *
     * Renouvelle l'abonnement du chauffeur : débite 500 F et ajoute 10 courses au compteur.
     * Le solde principal est débité en premier ; si insuffisant (ex. portefeuille à 0),
     * le solde bonus couvre le reste.
     */
    public function renew(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $driver */
        $driver = Auth::user();

        if (!$driver || !$driver->isDriver()) {
            return $this->apiError('FORBIDDEN', 'Accès réservé aux chauffeurs.', 403);
        }

        try {
            return DB::transaction(function () use ($driver) {
                $price = self::SUBSCRIPTION_PRICE;

                // Verrouiller le portefeuille pour mise à jour
                $wallet = DB::table('wallets')
                    ->where('user_id', $driver->id)
                    ->lockForUpdate()
                    ->first();

                $balance = $wallet ? (int) $wallet->balance : 0;
                $bonus = $wallet ? (int) ($wallet->bonus_balance ?? 0) : 0;

                if ($balance + $bonus < $price) {
                    $detail = $bonus > 0
                        ? "Votre solde ({$balance} F) et votre bonus ({$bonus} F) sont insuffisants"
                        : "Votre solde de portefeuille ({$balance} F) est insuffisant";
                    return $this->apiError(
                        'INSUFFICIENT_BALANCE',
                        "{$detail} pour acheter l'abonnement de {$price} F. Veuillez recharger votre portefeuille.",
                        422
                    );
                }

                // 1. Débiter le solde principal d'abord, le bonus couvre le reste
                $fromBalance = min($balance, $price);
                $fromBonus = $price - $fromBalance;
                $afterBalance = $balance - $fromBalance;
                $afterBonus = $bonus - $fromBonus;

                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $afterBalance,
                    'bonus_balance' => $afterBonus,
                    'updated_at' => now(),
                ]);

                // 2. Enregistrer la ou les transactions (part solde / part bonus)
                if ($fromBalance > 0) {
                    DB::table('wallet_transactions')->insert([
                        'wallet_id' => $wallet->id,
                        'type' => 'debit',
                        'source' => 'subscription_fee',
                        'amount' => $fromBalance,
                        'balance_before' => $balance,
                        'balance_after' => $afterBalance,
                        'meta' => json_encode(['desc' => 'Abonnement 10 courses Kêkênon', 'bonus_used' => $fromBonus]),
                        'created_at' => now(),
                    ]);
                }
                if ($fromBonus > 0) {
                    DB::table('wallet_transactions')->insert([
                        'wallet_id' => $wallet->id,
                        'type' => 'debit',
                        'source' => 'subscription_fee_bonus',
                        'amount' => $fromBonus,
                        // Le solde principal ne bouge pas sur la part bonus
                        'balance_before' => $afterBalance,
                        'balance_after' => $afterBalance,
                        'meta' => json_encode([
                            'desc' => 'Abonnement payé avec le bonus Kêkênon',
                            'bonus_before' => $bonus,
                            'bonus_after' => $afterBonus,
                        ]),
                        'created_at' => now(),
                    ]);
                }

                // 3. Créditer les 10 courses au compteur
                $profile = DB::table('driver_profiles')
                    ->where('user_id', $driver->id)
                    ->lockForUpdate()
                    ->first();

                if (!$profile) {
                    return $this->apiError('PROFILE_NOT_FOUND', 'Profil de chauffeur introuvable.', 404);
                }

                $newRidesCount = (int) $profile->subscription_remaining_rides + 10;

                DB::table('driver_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'subscription_remaining_rides' => $newRidesCount,
                        'updated_at' => now(),
                    ]);

                Log::info('Driver subscription renewed successfully', [
                    'driver_id' => $driver->id,
                    'previous_balance' => $balance,
                    'new_balance' => $afterBalance,
                    'bonus_used' => $fromBonus,
                    'new_bonus_balance' => $afterBonus,
                    'remaining_rides' => $newRidesCount,
                ]);

                return response()->json([
                    'ok' => true,
                    'message' => $fromBonus > 0
                        ? "Abonnement activé (dont {$fromBonus} F payés avec votre bonus) : 10 courses supplémentaires."
                        : 'Votre abonnement a été activé avec succès pour 10 courses supplémentaires.',
                    'subscription_remaining_rides' => $newRidesCount,
                    'wallet_balance' => $afterBalance,
                    'bonus_balance' => $afterBonus,
                    'bonus_used' => $fromBonus,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Driver subscription renewal failed', [
                'driver_id' => $driver->id,
                'error' => $e->getMessage(),
            ]);
            return $this->apiError('SERVER_ERROR', 'Une erreur est survenue lors de l\'activation de l\'abonnement.', 500);
        }
    }
}
