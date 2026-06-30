<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    /**
     * List all drivers with their wallet balances (including debts).
     * GET /api/admin/drivers/debts
     */
    public function driversDebts(Request $request)
    {
        $query = DB::table('users')
            ->leftJoin('wallets', 'users.id', '=', 'wallets.user_id')
            ->leftJoin('driver_profiles', 'users.id', '=', 'driver_profiles.user_id')
            ->where('users.role', 'driver')
            ->select([
                'users.id',
                'users.name',
                'users.phone',
                'users.email',
                'users.is_blocked',
                'users.created_at',
                'wallets.id as wallet_id',
                'wallets.balance',
                'wallets.currency',
                'driver_profiles.license_plate',
                'driver_profiles.vehicle_make',
                'driver_profiles.vehicle_model',
            ])
            ->orderBy('wallets.balance', 'asc'); // Debts first (negative values)

        // Optional filters
        if ($request->has('only_debts') && $request->boolean('only_debts')) {
            $query->where('wallets.balance', '<', 0);
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 50);
        $drivers = $query->paginate($perPage);

        // Add computed fields
        $drivers->getCollection()->transform(function ($driver) {
            $driver->balance = $driver->balance ?? 0;
            $driver->currency = $driver->currency ?? 'XOF';
            $driver->has_debt = $driver->balance < 0;
            $driver->debt_amount = $driver->balance < 0 ? abs($driver->balance) : 0;
            $driver->debt_level = \App\Support\DriverDebt::level($driver->balance); // ok|notify|alert|blocked
            return $driver;
        });

        return response()->json($drivers);
    }

    /**
     * Record a payment or adjustment to a driver's wallet.
     * POST /api/admin/wallets/{walletId}/adjust
     */
    public function adjustBalance(Request $request, int $walletId)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric'],
            'type' => ['required', 'string', 'in:credit,debit'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $wallet = DB::table('wallets')->where('id', $walletId)->first();
        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $amount = abs((int) $data['amount']);
        $before = (int) $wallet->balance;
        $after = $data['type'] === 'credit'
            ? $before + $amount
            : $before - $amount;

        DB::transaction(function () use ($walletId, $data, $amount, $before, $after) {
            // Record transaction
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $walletId,
                'type' => $data['type'],
                'source' => 'admin_adjustment',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => json_encode([
                    'reason' => $data['reason'],
                    'admin_user' => auth()->id(),
                ]),
                'created_at' => now(),
            ]);

            // Update wallet balance
            DB::table('wallets')->where('id', $walletId)->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);
        });

        Log::info('Admin wallet adjustment', [
            'wallet_id' => $walletId,
            'type' => $data['type'],
            'amount' => $amount,
            'reason' => $data['reason'],
            'before' => $before,
            'after' => $after,
            'admin_id' => auth()->id(),
        ]);

        return response()->json([
            'ok' => true,
            'wallet_id' => $walletId,
            'balance_before' => $before,
            'balance_after' => $after,
        ]);
    }

    /**
     * Get wallet transaction history.
     * GET /api/admin/wallets/{walletId}/transactions
     */
    public function transactions(Request $request, int $walletId)
    {
        $wallet = DB::table('wallets')->where('id', $walletId)->first();
        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $perPage = $request->input('per_page', 20);
        $transactions = DB::table('wallet_transactions')
            ->where('wallet_id', $walletId)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'wallet' => $wallet,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Block a driver.
     * POST /api/admin/drivers/{driverId}/block
     */
    public function blockDriver(Request $request, int $driverId)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $driver = DB::table('users')->where('id', $driverId)->where('role', 'driver')->first();
        if (!$driver) {
            return response()->json(['message' => 'Driver not found'], 404);
        }

        DB::table('users')->where('id', $driverId)->update([
            'is_blocked' => true,
            'blocked_reason' => $data['reason'] ?? 'Blocked by admin',
            'blocked_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Admin blocked driver', [
            'driver_id' => $driverId,
            'reason' => $data['reason'] ?? 'Blocked by admin',
            'admin_id' => auth()->id(),
        ]);

        return response()->json([
            'ok' => true,
            'driver_id' => $driverId,
            'is_blocked' => true,
        ]);
    }

    /**
     * Unblock a driver.
     * POST /api/admin/drivers/{driverId}/unblock
     */
    public function unblockDriver(int $driverId)
    {
        $driver = DB::table('users')->where('id', $driverId)->where('role', 'driver')->first();
        if (!$driver) {
            return response()->json(['message' => 'Driver not found'], 404);
        }

        DB::table('users')->where('id', $driverId)->update([
            'is_blocked' => false,
            'blocked_reason' => null,
            'blocked_at' => null,
            'updated_at' => now(),
        ]);

        Log::info('Admin unblocked driver', [
            'driver_id' => $driverId,
            'admin_id' => auth()->id(),
        ]);

        return response()->json([
            'ok' => true,
            'driver_id' => $driverId,
            'is_blocked' => false,
        ]);
    }
}
