<?php

namespace Tests\Feature;

use App\Models\PricingSetting;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EconomicModelFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_model_and_finance_use_real_platform_revenue_sources(): void
    {
        PricingSetting::create([
            'base_fare' => 700,
            'per_km' => 200,
            'min_fare' => 1000,
            'passenger_app_fee' => 40,
            'driver_pack_price' => 1200,
            'driver_pack_rides' => 20,
        ]);

        $this->getJson('/api/economic-model')
            ->assertOk()
            ->assertJson([
                'driver_ride_share_pct' => 100,
                'passenger_app_fee' => 40,
                'driver_pack_price' => 1200,
                'driver_pack_rides' => 20,
                'driver_effective_fee_per_ride' => 60,
                'expected_platform_revenue_per_ride' => 100,
            ]);

        $passenger = User::factory()->create(['role' => 'passenger']);
        $driver = User::factory()->create(['role' => 'driver']);
        $admin = User::factory()->create(['role' => 'admin']);

        $passengerWalletId = DB::table('wallets')->insertGetId([
            'user_id' => $passenger->id,
            'balance' => 1000,
            'bonus_balance' => 0,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverWalletId = DB::table('wallets')->insertGetId([
            'user_id' => $driver->id,
            'balance' => 1000,
            'bonus_balance' => 0,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            [$passengerWalletId, 'app_fee', 40],
            [$driverWalletId, 'subscription_fee', 1200],
            [$driverWalletId, 'subscription_fee_bonus', 300],
        ] as [$walletId, $source, $amount]) {
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $walletId,
                'type' => 'debit',
                'source' => $source,
                'amount' => $amount,
                'balance_before' => 2000,
                'balance_after' => 2000 - $amount,
                'created_at' => now(),
            ]);
        }

        Ride::create([
            'rider_id' => $passenger->id,
            'driver_id' => $driver->id,
            'status' => 'completed',
            'fare_amount' => 1000,
            'original_fare_amount' => 1500,
            'driver_earnings_amount' => 1500,
            'commission_amount' => 0,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $from = rawurlencode(now()->subHour()->toIso8601String());
        $to = rawurlencode(now()->addHour()->toIso8601String());

        $this->getJson("/api/admin/finance/summary?from={$from}&to={$to}")
            ->assertOk()
            ->assertJson([
                'gross_volume' => 1000,
                'net_revenue' => 1240,
                'passenger_app_fees' => 40,
                'driver_pack_revenue' => 1200,
                'bonus_consumed' => 300,
                'promo_subsidies' => 500,
                'net_platform_margin' => 740,
                'rides_count' => 1,
            ]);
    }

    public function test_configured_pack_is_used_by_driver_renewal(): void
    {
        PricingSetting::create([
            'driver_pack_price' => 1200,
            'driver_pack_rides' => 20,
        ]);
        $driver = User::factory()->create(['role' => 'driver']);
        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'status' => 'approved',
            'subscription_remaining_rides' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('wallets')->insert([
            'user_id' => $driver->id,
            'balance' => 1200,
            'bonus_balance' => 0,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/subscription/renew')
            ->assertOk()
            ->assertJson([
                'wallet_balance' => 0,
                'subscription_remaining_rides' => 20,
                'driver_pack_price' => 1200,
                'driver_pack_rides' => 20,
            ]);
    }
}
