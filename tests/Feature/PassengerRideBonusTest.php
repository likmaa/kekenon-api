<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PassengerRideBonusTest extends TestCase
{
    use RefreshDatabase;

    private function makePassenger(): User
    {
        $passenger = User::factory()->create(['role' => 'passenger']);

        DB::table('wallets')->insert([
            'user_id' => $passenger->id,
            'balance' => 1000,
            'bonus_balance' => 0,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $passenger;
    }

    public function test_wallet_exposes_first_ride_bonus_for_eligible_passenger(): void
    {
        $passenger = $this->makePassenger();
        Sanctum::actingAs($passenger);

        $this->getJson('/api/passenger/wallet')
            ->assertOk()
            ->assertJson([
                'balance' => 1000,
                'ride_bonus_balance' => 500,
            ]);
    }

    public function test_wallet_hides_first_ride_bonus_after_a_ride_has_been_used(): void
    {
        $passenger = $this->makePassenger();
        $driver = User::factory()->create(['role' => 'driver']);

        DB::table('rides')->insert([
            'rider_id' => $passenger->id,
            'driver_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/passenger/wallet')
            ->assertOk()
            ->assertJson([
                'ride_bonus_balance' => 0,
            ]);
    }
}
