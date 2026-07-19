<?php

namespace Tests\Feature;

use App\Models\PricingSetting;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryFlowTest extends TestCase
{
    use RefreshDatabase;

    private function passenger(): User
    {
        $user = User::factory()->create(['role' => 'passenger']);
        DB::table('wallets')->insert([
            'user_id' => $user->id,
            'balance' => 10000,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Désactive le bonus de première course pour rendre la grille testée lisible.
        Ride::create(['rider_id' => $user->id, 'status' => 'completed', 'fare_amount' => 1000, 'completed_at' => now()]);

        return $user;
    }

    private function driver(): User
    {
        $user = User::factory()->create(['role' => 'driver', 'is_online' => true]);
        DB::table('driver_profiles')->insert([
            'user_id' => $user->id,
            'status' => 'approved',
            'subscription_remaining_rides' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('wallets')->insert([
            'user_id' => $user->id,
            'balance' => 0,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'pickup' => ['lat' => 6.40, 'lng' => 2.33, 'label' => 'Expéditeur'],
            'dropoff' => ['lat' => 6.41, 'lng' => 2.34, 'label' => 'Destinataire'],
            'distance_m' => 1000,
            'duration_s' => 300,
            'vehicle_type' => 'standard',
            'payment_method' => 'wallet',
            'service_type' => 'livraison',
            'package_size' => 'medium',
            'package_weight' => 7,
            'package_description' => 'Documents administratifs',
            'is_fragile' => true,
            'recipient_name' => 'Awa Test',
            'recipient_phone' => '97 00 00 00',
        ];
    }

    public function test_delivery_requires_recipient_and_package_information(): void
    {
        Sanctum::actingAs($this->passenger());
        $payload = $this->payload();
        unset($payload['recipient_name'], $payload['package_description'], $payload['package_size']);

        $this->postJson('/api/trips/create', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_name', 'package_description', 'package_size']);
    }

    public function test_delivery_pricing_data_visibility_code_and_wallet_category(): void
    {
        PricingSetting::create([
            'base_fare' => 700,
            'per_km' => 200,
            'per_min' => 0,
            'min_fare' => 1000,
            'passenger_app_fee' => 50,
            'driver_pack_price' => 500,
            'driver_pack_rides' => 10,
            'delivery_medium_fee' => 200,
            'delivery_fragile_fee' => 200,
            'delivery_weight_threshold_kg' => 5,
            'delivery_extra_kg_fee' => 100,
        ]);

        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);
        $created = $this->postJson('/api/trips/create', $this->payload())
            ->assertCreated()
            ->assertJsonPath('service_type', 'livraison')
            ->assertJsonPath('package_size', 'medium');

        $rideId = (int) $created->json('id');
        $code = (string) $created->json('delivery_code');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);

        $ride = Ride::findOrFail($rideId);
        $this->assertSame(1600, (int) $ride->original_fare_amount);
        $this->assertNotSame($code, $ride->delivery_code_hash);

        $driver = $this->driver();
        $ride->update(['driver_id' => $driver->id, 'status' => 'ongoing', 'started_at' => now()]);

        Sanctum::actingAs($driver);
        $this->getJson("/api/driver/rides/{$rideId}")
            ->assertOk()
            ->assertJsonPath('recipient_name', 'Awa Test')
            ->assertJsonMissingPath('delivery_code')
            ->assertJsonMissingPath('delivery_code_encrypted');

        $this->postJson("/api/driver/trips/{$rideId}/complete", ['distance_m' => 1000, 'delivery_code' => '0000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_DELIVERY_CODE');

        $this->postJson("/api/driver/trips/{$rideId}/complete", ['distance_m' => 1000, 'delivery_code' => $code])
            ->assertOk();

        $ride->refresh();
        $this->assertNotNull($ride->delivery_confirmed_at);
        $this->assertSame(1600, (int) $ride->driver_earnings_amount);

        Sanctum::actingAs($passenger);
        $this->postJson("/api/passenger/rides/{$rideId}/pay", ['method' => 'wallet'])
            ->assertOk();
        $this->getJson('/api/passenger/wallet/transactions/history')
            ->assertOk()
            ->assertJsonFragment(['type' => 'delivery', 'description' => 'Paiement livraison']);
    }
}
