<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class WithdrawController extends Controller
{
    /**
     * Store a new withdrawal request.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:500'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'account_identifier' => ['nullable', 'string', 'max:100'],
        ]);

        $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
        
        if (!$wallet || $wallet->balance < $data['amount']) {
            return response()->json([
                'message' => 'Solde insuffisant pour effectuer ce retrait.'
            ], 422);
        }

        return DB::transaction(function () use ($user, $wallet, $data) {
            $before = (int) $wallet->balance;
            $after = $before - (int) $data['amount'];

            // Record the withdrawal in wallet transactions
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'source' => 'withdrawal',
                'amount' => $data['amount'],
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => json_encode([
                    'payment_method' => $data['payment_method'] ?? 'default',
                    'account_identifier' => $data['account_identifier'] ?? null,
                    'status' => 'pending'
                ]),
                'created_at' => now(),
            ]);

            // Update wallet balance
            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            // For now, we also record it in a separate withdrawal_requests table if it exists
            // Since we haven't created one yet, we'll just use the transaction log.
            // In a production environment, you'd want a dedicated table for moderation.

            return response()->json([
                'ok' => true,
                'message' => 'Votre demande de retrait a été enregistrée et est en cours de traitement.',
                'new_balance' => $after
            ]);
        });
    }
}
