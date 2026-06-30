<?php

namespace Tests\Feature;

use App\Models\PricingSetting;
use App\Models\PromoCode;
use App\Models\Ride;
use App\Models\User;
use App\Services\RideCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RidePromoSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_100_percent_discount_ride_completion()
    {
        // 1. Setup Pricing
        PricingSetting::create([
            'base_fare' => 500,
            'per_km' => 250,
            'min_fare' => 1000,
            'platform_commission_pct' => 15, // Total platform + maintenance usually
            'driver_commission_pct' => 75,
            'maintenance_commission_pct' => 10,
        ]);

        // 2. Create Users
        $passenger = User::factory()->create(['role' => 'passenger']);
        $driver = User::factory()->create(['role' => 'driver']);

        // Ensure driver has a wallet
        DB::table('wallets')->insert([
            'user_id' => $driver->id,
            'balance' => 0,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Create 100% Promo Code
        $promo = PromoCode::create([
            'code' => 'FREE100',
            'type' => 'percentage',
            'value' => 100,
            'max_uses' => 10,
            'used_count' => 0,
            'expires_at' => now()->addDays(1),
            'is_active' => true,
        ]);

        // 4. Create a Ride with the promo
        $ride = Ride::create([
            'rider_id' => $passenger->id,
            'driver_id' => $driver->id,
            'status' => 'ongoing',
            'promo_code_id' => $promo->id,
            'pickup_lat' => 6.367,
            'pickup_lng' => 2.425,
            'dropoff_lat' => 6.370,
            'dropoff_lng' => 2.430,
            'distance_m' => 2000, // 2km
            'vehicle_type' => 'standard',
            'payment_method' => 'cash',
        ]);

        // 5. Run RideCompletionService
        $service = app(RideCompletionService::class);
        $result = $service->complete($ride, 2000);

        $completedRide = $result['ride'];

        // Calculations:
        // Trajectory: 500 (base) + 2 * 250 (km) = 1000
        // Min fare is 1000, so original_fare = 1000
        // Discount 100% = 1000
        // Final fare = 0

        // Driver Earnings (on original): 1000 * 0.75 = 750
        // Maintenance (on original): 1000 * 0.10 = 100
        // Platform Net Cut: 0 - 750 - 100 = -850

        $this->assertEquals(1000, $completedRide->original_fare_amount, 'Original fare should be 1000');
        $this->assertEquals(1000, $completedRide->discount_amount, 'Discount should be 1000');
        $this->assertEquals(0, $completedRide->fare_amount, 'Passenger should pay 0');
        $this->assertEquals(750, $completedRide->driver_earnings_amount, 'Driver should earn 750');

        // Platform commission in DB is stored as platformNetCut + maintenanceAmount
        // commission_amount = -850 + 100 = -750
        $this->assertEquals(-750, $completedRide->commission_amount, 'Commission amount should be -750 (platform absorbed the loss)');

        // Check wallet transaction
        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();
        // Since it's cash and commission is -750, deducting -750 means adding 750
        // balance = 0 - (-750) = 750
        $this->assertEquals(750, $wallet->balance, 'Driver wallet should be credited with 750 even if passenger paid 0');

        // 6. Test End-to-End Flow for Promo Creation and Arbitrage
        $this->test_promo_arbitrage_and_usage($passenger, $driver);
    }

    protected function test_promo_arbitrage_and_usage($passenger, $driver)
    {
        // Create a 10% promo
        $promo10 = PromoCode::create([
            'code' => 'SAVE10',
            'type' => 'percentage',
            'value' => 10,
            'max_uses' => 100,
            'used_count' => 0,
            'expires_at' => now()->addDays(1),
            'is_active' => true,
        ]);

        // Case A: First ride (30% discount) vs 10% promo -> Should choose 30% discount and NOT use promo
        $passenger->rides()->delete(); // Ensure it's the first ride
        $this->actingAs($passenger);
        $response = $this->postJson('/api/trips/create', [
            'pickup' => ['lat' => 6.367, 'lng' => 2.425, 'label' => 'Pickup'],
            'dropoff' => ['lat' => 6.370, 'lng' => 2.430, 'label' => 'Dropoff'],
            'distance_m' => 2000,
            'duration_s' => 300,
            'promo_code' => 'SAVE10',
            'vehicle_type' => 'standard',
        ]);

        $response->assertStatus(201);
        $rideId = $response->json('id');
        $ride = Ride::find($rideId);

        $this->assertEquals(300, $ride->discount_amount, 'Should apply 30% of 1000 = 300');
        $this->assertNull($ride->promo_code_id, 'Should NOT use promo code if 30% discount is better');
        $this->assertEquals(0, $promo10->refresh()->used_count, 'Promo count should NOT increment');

        // Case B: 50% promo vs 30% first ride -> Should choose 50% promo
        $promo50 = PromoCode::create([
            'code' => 'SAVE50',
            'type' => 'percentage',
            'value' => 50,
            'max_uses' => 100,
            'used_count' => 0,
            'is_active' => true,
        ]);

        // Reset passenger rides to test "first ride" logic again (simplified for test)
        $passenger->rides()->delete();

        $response = $this->postJson('/api/trips/create', [
            'pickup' => ['lat' => 6.367, 'lng' => 2.425, 'label' => 'Pickup'],
            'dropoff' => ['lat' => 6.370, 'lng' => 2.430, 'label' => 'Dropoff'],
            'distance_m' => 2000,
            'duration_s' => 300,
            'promo_code' => 'SAVE50',
            'vehicle_type' => 'standard',
        ]);

        $response->assertStatus(201);
        $ride = Ride::find($response->json('id'));
        $this->assertEquals(500, $ride->discount_amount, 'Should apply 50% of 1000 = 500');
        $this->assertEquals($promo50->id, $ride->promo_code_id, 'Should use promo code if it is better than 30%');
        $this->assertEquals(1, $promo50->refresh()->used_count, 'Promo count SHOULD increment');

        // Case C: Second ride (no 30% discount) with 10% promo -> Should use 10% promo
        // (Passenger now has 1 completed ride in theory, but here we just need to ensure count() > 0)

        $response = $this->postJson('/api/trips/create', [
            'pickup' => ['lat' => 6.367, 'lng' => 2.425, 'label' => 'Pickup'],
            'dropoff' => ['lat' => 6.370, 'lng' => 2.430, 'label' => 'Dropoff'],
            'distance_m' => 2000,
            'duration_s' => 300,
            'promo_code' => 'SAVE10',
            'vehicle_type' => 'standard',
        ]);

        $response->assertStatus(201);
        $ride = Ride::find($response->json('id'));
        $this->assertEquals(100, $ride->discount_amount, 'Should apply 10% of 1000 = 100');
        $this->assertEquals($promo10->id, $ride->promo_code_id);
        $this->assertEquals(1, $promo10->refresh()->used_count);
    }
}
