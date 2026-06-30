<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Support\DriverDebt;
use Carbon\Carbon;

class ExternalRevenueController extends Controller
{
    /**
     * Enregistrer une recette hors-application pour un chauffeur.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'driver_id' => 'required|exists:users,id',
            'total_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'date' => 'nullable|date',
        ]);

        $driver = User::where('id', $data['driver_id'])->where('role', 'driver')->firstOrFail();

        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();

        if (!$wallet) {
            return response()->json(['message' => 'Le chauffeur n\'a pas de portefeuille actif.'], 422);
        }

        $totalAmount = (float) $data['total_amount'];
        $rate = $data['commission_rate'] ?? 80;
        $commissionAmount = ($totalAmount * $rate) / 100;

        $createdAt = $data['date'] ? Carbon::parse($data['date'])->setTimeFrom(now()) : now();

        return DB::transaction(function () use ($wallet, $totalAmount, $commissionAmount, $data, $createdAt) {

            // 1. Enregistrer la recette totale (pour les statistiques de CA)
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'source' => 'external_revenue',
                'amount' => 0,
                'balance_before' => $wallet->balance,
                'balance_after' => $wallet->balance,
                'created_at' => $createdAt,
                'meta' => json_encode([
                    'total_fare' => $totalAmount,
                    'description' => $data['description'] ?? 'Course hors-application',
                    'commission_applied' => $commissionAmount
                ])
            ]);

            // 2. Déduire la commission du portefeuille (Crée la dette)
            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore - $commissionAmount;

            DB::table('wallets')->where('id', $wallet->id)->decrement('balance', $commissionAmount);

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'source' => 'commission',
                'amount' => $commissionAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'created_at' => $createdAt,
                'meta' => json_encode([
                    'description' => "Commission sur course hors-app du " . $createdAt->format('d/m/Y'),
                    'related_to_external' => true,
                    'external_amount' => $totalAmount
                ])
            ]);

            return response()->json([
                'message' => 'Recette enregistrée avec succès.',
                'commission_deducted' => $commissionAmount,
                'new_balance' => $balanceAfter
            ]);
        });
    }
}
