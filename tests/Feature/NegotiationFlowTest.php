<?php

namespace Tests\Feature;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flow de course : création (fixe / négociable), acceptation (revendication),
 * négociation verbale (confirm / reject) et gating « Aller chercher mon client ».
 */
class NegotiationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makePassenger(): User
    {
        $p = User::factory()->create(['role' => 'passenger']);
        DB::table('wallets')->insert([
            'user_id' => $p->id, 'balance' => 5000, 'currency' => 'XOF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $p;
    }

    private function makeDriver(): User
    {
        $d = User::factory()->create(['role' => 'driver']);
        DB::table('driver_profiles')->insert([
            'user_id' => $d->id, 'status' => 'approved',
            'subscription_remaining_rides' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('wallets')->insert([
            'user_id' => $d->id, 'balance' => 0, 'currency' => 'XOF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $d;
    }

    private function createRide(User $passenger, string $pricingMode): int
    {
        Sanctum::actingAs($passenger);
        $res = $this->postJson('/api/trips/create', [
            'pickup' => ['lat' => 6.4038, 'lng' => 2.3278, 'label' => 'A'],
            'dropoff' => ['lat' => 6.3702, 'lng' => 2.3912, 'label' => 'B'],
            'distance_m' => 8200,
            'vehicle_type' => 'standard',
            'payment_method' => 'cash',
            'service_type' => 'course',
            'pricing_mode' => $pricingMode,
        ]);
        $res->assertStatus(201);
        return (int) $res->json('id');
    }

    /** Régression : le pricing_mode « negotiable » doit être persisté (bug closure use()). */
    public function test_create_persists_negotiable_pricing_mode(): void
    {
        $passenger = $this->makePassenger();
        $rideId = $this->createRide($passenger, 'negotiable');

        $this->assertSame('negotiable', Ride::find($rideId)->pricing_mode);
    }

    public function test_fixed_ride_accept_is_immediately_confirmed(): void
    {
        $passenger = $this->makePassenger();
        $rideId = $this->createRide($passenger, 'fixed');
        $driver = $this->makeDriver();

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/trips/{$rideId}/accept")
            ->assertOk()
            ->assertJson(['ok' => true, 'pricing_mode' => 'fixed', 'negotiation_confirmed' => true]);
    }

    public function test_negotiable_ride_requires_passenger_confirmation(): void
    {
        $passenger = $this->makePassenger();
        $rideId = $this->createRide($passenger, 'negotiable');
        $driver = $this->makeDriver();

        // Le zem revendique : accepté mais NON confirmé.
        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/trips/{$rideId}/accept")
            ->assertOk()
            ->assertJson(['pricing_mode' => 'negotiable', 'negotiation_confirmed' => false]);
        $this->getJson("/api/driver/rides/{$rideId}")
            ->assertOk()
            ->assertJson(['negotiation_confirmed' => false]);
        // current-ride (source du gating côté zem) doit aussi renvoyer false,
        // sinon « Aller chercher mon client » s'active à tort après re-sync.
        $this->getJson('/api/driver/current-ride')
            ->assertOk()
            ->assertJson(['pricing_mode' => 'negotiable', 'negotiation_confirmed' => false]);

        // Le passager confirme (prix convenu).
        Sanctum::actingAs($passenger);
        $this->postJson("/api/passenger/rides/{$rideId}/confirm-negotiation", ['agreed_fare' => 2500])
            ->assertOk()
            ->assertJson(['ok' => true, 'negotiation_confirmed' => true, 'fare' => 2500]);

        // « Aller chercher mon client » est désormais débloqué côté zem.
        Sanctum::actingAs($driver);
        $this->getJson("/api/driver/rides/{$rideId}")
            ->assertOk()
            ->assertJson(['negotiation_confirmed' => true, 'negotiated_fare' => 2500]);
    }

    public function test_reject_negotiation_releases_ride_back_to_pool(): void
    {
        $passenger = $this->makePassenger();
        $rideId = $this->createRide($passenger, 'negotiable');
        $driver = $this->makeDriver();

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/trips/{$rideId}/accept")->assertOk();

        Sanctum::actingAs($passenger);
        $this->postJson("/api/passenger/rides/{$rideId}/reject-negotiation")
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'requested']);

        $ride = Ride::find($rideId);
        $this->assertSame('requested', $ride->status);
        $this->assertNull($ride->driver_id);
        $this->assertContains($driver->id, $ride->declined_driver_ids ?? []);
    }

    public function test_second_driver_gets_ride_not_available(): void
    {
        $passenger = $this->makePassenger();
        $rideId = $this->createRide($passenger, 'fixed');
        $d1 = $this->makeDriver();
        $d2 = $this->makeDriver();

        Sanctum::actingAs($d1);
        $this->postJson("/api/driver/trips/{$rideId}/accept")->assertOk();

        Sanctum::actingAs($d2);
        $this->postJson("/api/driver/trips/{$rideId}/accept")
            ->assertStatus(422)
            ->assertJson(['code' => 'RIDE_NOT_AVAILABLE']);
    }

    public function test_driver_complete_is_idempotent_after_network_retry(): void
    {
        $passenger = $this->makePassenger();
        $driver = $this->makeDriver();
        $ride = Ride::create([
            'rider_id' => $passenger->id,
            'driver_id' => $driver->id,
            'status' => 'ongoing',
            'pickup_lat' => 6.4038,
            'pickup_lng' => 2.3278,
            'dropoff_lat' => 6.3702,
            'dropoff_lng' => 2.3912,
            'distance_m' => 3000,
            'vehicle_type' => 'standard',
            'payment_method' => 'cash',
            'started_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/trips/{$ride->id}/complete", ['distance_m' => 3000])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'completed']);

        $this->postJson("/api/driver/trips/{$ride->id}/complete", ['distance_m' => 3000])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'completed',
                'already_completed' => true,
            ]);
    }

    public function test_driver_can_complete_legacy_started_ride(): void
    {
        $passenger = $this->makePassenger();
        $driver = $this->makeDriver();
        $ride = Ride::create([
            'rider_id' => $passenger->id,
            'driver_id' => $driver->id,
            'status' => 'started',
            'pickup_lat' => 6.4038,
            'pickup_lng' => 2.3278,
            'dropoff_lat' => 6.3702,
            'dropoff_lng' => 2.3912,
            'distance_m' => 3000,
            'vehicle_type' => 'standard',
            'payment_method' => 'cash',
            'started_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/trips/{$ride->id}/complete", ['distance_m' => 3000])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'completed']);
    }
}
