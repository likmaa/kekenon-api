<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Ride;
use App\Models\Rating;
use App\Models\DriverReward;
use App\Events\RideRated;

class RatingsController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'ride_id' => ['required', 'integer', 'min:1'],
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
            'tip_amount' => ['nullable', 'integer', 'min:0', 'max:50000'],
        ]);

        $ride = Ride::findOrFail($data['ride_id']);
        if ($ride->rider_id !== ($user?->id)) {
            return response()->json(['message' => 'Not your ride'], 403);
        }
        if ($ride->status !== 'completed') {
            return response()->json(['message' => 'Ride not completed'], 422);
        }
        if (!$ride->driver_id) {
            return response()->json(['message' => 'No driver assigned'], 422);
        }

        $exists = Rating::where('ride_id', $ride->id)->where('passenger_id', $user?->id)->exists();
        if ($exists) {
            return response()->json(['message' => 'Rating already submitted'], 409);
        }

        $reward = null;

        DB::transaction(function () use ($ride, $user, $data, &$reward) {
            Rating::create([
                'ride_id' => $ride->id,
                'driver_id' => $ride->driver_id,
                'passenger_id' => $user?->id,
                'stars' => (int) $data['stars'],
                'comment' => $data['comment'] ?? null,
                'created_at' => now(),
            ]);

            $totalPoints = (int) DB::table('ratings')->where('driver_id', $ride->driver_id)->sum('stars');
            $lastThreshold = (int) DB::table('driver_rewards')->where('driver_id', $ride->driver_id)->max('points_threshold');

            $currentThreshold = (int) (floor($totalPoints / 100) * 100);
            if ($currentThreshold >= 100 && $currentThreshold > $lastThreshold) {
                // Award for each missing 100-points step (e.g., 100, 200, ...)
                for ($pt = $lastThreshold + 100; $pt <= $currentThreshold; $pt += 100) {
                    DB::table('driver_rewards')->insert([
                        'driver_id' => $ride->driver_id,
                        'points_threshold' => $pt,
                        'amount' => 5000,
                        'created_at' => now(),
                    ]);
                }
                $reward = [
                    'points_threshold' => $currentThreshold,
                    'amount' => 5000,
                ];
            }

            // Handle Tip (plafond aligné backlog SEC-03)
            $tip = min(50000, (int) ($data['tip_amount'] ?? 0));
            if ($tip > 0) {
                // Debit rider wallet first
                $riderWallet = DB::table('wallets')
                    ->where('user_id', $ride->rider_id)
                    ->lockForUpdate()
                    ->first();

                if (!$riderWallet || (int) $riderWallet->balance < $tip) {
                    $tip = $riderWallet ? (int) $riderWallet->balance : 0;
                }

                if ($tip > 0) {
                    $riderBefore = (int) $riderWallet->balance;
                    $riderAfter = $riderBefore - $tip;

                    DB::table('wallet_transactions')->insert([
                        'wallet_id' => $riderWallet->id,
                        'type' => 'debit',
                        'source' => 'ride_tip',
                        'amount' => $tip,
                        'balance_before' => $riderBefore,
                        'balance_after' => $riderAfter,
                        'meta' => json_encode(['ride_id' => $ride->id]),
                        'created_at' => now(),
                    ]);

                    DB::table('wallets')->where('id', $riderWallet->id)->update([
                        'balance' => $riderAfter,
                        'updated_at' => now(),
                    ]);

                    $ride->tip_amount = $tip;
                    $ride->save();

                    // Credit driver wallet for TIP
                    $wallet = DB::table('wallets')
                        ->where('user_id', $ride->driver_id)
                        ->lockForUpdate()
                        ->first();

                    if ($wallet) {
                        $before = (int) $wallet->balance;
                        $after = $before + $tip;

                        DB::table('wallet_transactions')->insert([
                            'wallet_id' => $wallet->id,
                            'type' => 'credit',
                            'source' => 'ride_tip',
                            'amount' => $tip,
                            'balance_before' => $before,
                            'balance_after' => $after,
                            'meta' => json_encode(['ride_id' => $ride->id]),
                            'created_at' => now(),
                        ]);

                        DB::table('wallets')->where('id', $wallet->id)->update([
                            'balance' => $after,
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            // Broadcast that the ride was rated (includes tip)
            rescue(fn () => broadcast(new RideRated($ride, (int) $data['stars'])));
        });

        return response()->json([
            'ok' => true,
            'driver_id' => $ride->driver_id,
            'ride_id' => $ride->id,
            'awarded' => $reward,
        ], 201);
    }
}
