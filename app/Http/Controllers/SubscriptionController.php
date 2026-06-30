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

    /**
     * POST /driver/subscription/renew
     * 
     * Renouvelle l'abonnement du chauffeur : débite 500 F et ajoute 10 courses au compteur.
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
                // Verrouiller le portefeuille pour mise à jour
                $wallet = DB::table('wallets')
                    ->where('user_id', $driver->id)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet || (int) $wallet->balance < 500) {
                    $balance = $wallet ? (int) $wallet->balance : 0;
                    return $this->apiError(
                        'INSUFFICIENT_BALANCE',
                        "Votre solde de portefeuille ({$balance} F) est insuffisant pour acheter l'abonnement de 500 F. Veuillez recharger votre portefeuille.",
                        422
                    );
                }

                // 1. Débiter les 500 F du solde
                $before = (int) $wallet->balance;
                $after = $before - 500;

                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $after,
                    'updated_at' => now(),
                ]);

                // 2. Enregistrer la transaction
                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $wallet->id,
                    'type' => 'debit',
                    'source' => 'subscription_fee',
                    'amount' => 500,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'meta' => json_encode(['desc' => 'Abonnement 10 courses Kêkênon']),
                    'created_at' => now(),
                ]);

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
                    'previous_balance' => $before,
                    'new_balance' => $after,
                    'remaining_rides' => $newRidesCount,
                ]);

                return response()->json([
                    'ok' => true,
                    'message' => 'Votre abonnement a été activé avec succès pour 10 courses supplémentaires.',
                    'subscription_remaining_rides' => $newRidesCount,
                    'wallet_balance' => $after,
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
