<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Solde bonus : offert par Kêkênon, dépensé automatiquement quand le solde
 * principal ne couvre pas l'abonnement chauffeur (500 F / 10 courses).
 */
class BonusWalletTest extends TestCase
{
    use RefreshDatabase;

    private function makeDriver(int $balance, int $bonus, int $remainingRides = 0): User
    {
        $driver = User::factory()->create(['role' => 'driver']);

        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'status' => 'approved',
            'subscription_remaining_rides' => $remainingRides,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallets')->insert([
            'user_id' => $driver->id,
            'balance' => $balance,
            'bonus_balance' => $bonus,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $driver;
    }

    private function wallet(User $driver): object
    {
        return DB::table('wallets')->where('user_id', $driver->id)->first();
    }

    public function test_wallet_endpoint_returns_bonus_balance(): void
    {
        $driver = $this->makeDriver(10000, 500);
        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/wallet')
            ->assertOk()
            ->assertJson(['balance' => 10000, 'bonus_balance' => 500]);
    }

    public function test_subscription_paid_with_bonus_when_wallet_is_empty(): void
    {
        $driver = $this->makeDriver(0, 500);
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/subscription/renew')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'wallet_balance' => 0,
                'bonus_balance' => 0,
                'bonus_used' => 500,
                'subscription_remaining_rides' => 10,
            ]);

        $wallet = $this->wallet($driver);
        $this->assertSame(0, (int) $wallet->balance);
        $this->assertSame(0, (int) $wallet->bonus_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'source' => 'subscription_fee_bonus',
            'type' => 'debit',
            'amount' => 500,
        ]);
        // Aucune part n'a été prise sur le solde principal
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'source' => 'subscription_fee',
        ]);
    }

    public function test_subscription_splits_between_balance_and_bonus(): void
    {
        $driver = $this->makeDriver(200, 500);
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/subscription/renew')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'wallet_balance' => 0,
                'bonus_balance' => 200, // 500 - 300 utilisés en complément
                'bonus_used' => 300,
            ]);

        $wallet = $this->wallet($driver);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'source' => 'subscription_fee',
            'amount' => 200,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'source' => 'subscription_fee_bonus',
            'amount' => 300,
        ]);
    }

    public function test_subscription_uses_balance_first_and_keeps_bonus(): void
    {
        $driver = $this->makeDriver(10000, 500);
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/subscription/renew')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'wallet_balance' => 9500,
                'bonus_balance' => 500,
                'bonus_used' => 0,
            ]);
    }

    public function test_subscription_rejected_when_balance_plus_bonus_insufficient(): void
    {
        $driver = $this->makeDriver(100, 300);
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/subscription/renew')
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'code' => 'INSUFFICIENT_BALANCE']);

        $wallet = $this->wallet($driver);
        $this->assertSame(100, (int) $wallet->balance);
        $this->assertSame(300, (int) $wallet->bonus_balance);
    }

    /** Course « ongoing » minimale prête à être complétée par le service. */
    private function makeOngoingRide(User $driver): \App\Models\Ride
    {
        $passenger = User::factory()->create(['role' => 'passenger']);

        return \App\Models\Ride::create([
            'rider_id' => $passenger->id,
            'driver_id' => $driver->id,
            'status' => 'ongoing',
            'pickup_lat' => 6.367,
            'pickup_lng' => 2.425,
            'dropoff_lat' => 6.370,
            'dropoff_lng' => 2.430,
            'distance_m' => 2000,
            'vehicle_type' => 'standard',
            'payment_method' => 'cash',
        ]);
    }

    public function test_subscription_auto_renews_from_wallet_when_pack_runs_out(): void
    {
        $driver = $this->makeDriver(10000, 500, 1); // dernière course du pack
        $ride = $this->makeOngoingRide($driver);

        app(\App\Services\RideCompletionService::class)->complete($ride);

        // 1 → 0 après la course, puis renouvellement auto : +10 courses, 500 F débités du solde
        $this->assertSame(10, (int) DB::table('driver_profiles')->where('user_id', $driver->id)->value('subscription_remaining_rides'));

        $wallet = $this->wallet($driver);
        $this->assertSame(9500, (int) $wallet->balance);
        $this->assertSame(500, (int) $wallet->bonus_balance); // bonus intact

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'source' => 'subscription_fee',
            'amount' => 500,
        ]);
    }

    public function test_subscription_auto_renews_from_bonus_when_wallet_is_empty(): void
    {
        $driver = $this->makeDriver(0, 500, 1);
        $ride = $this->makeOngoingRide($driver);

        app(\App\Services\RideCompletionService::class)->complete($ride);

        $this->assertSame(10, (int) DB::table('driver_profiles')->where('user_id', $driver->id)->value('subscription_remaining_rides'));

        $wallet = $this->wallet($driver);
        $this->assertSame(0, (int) $wallet->balance);
        $this->assertSame(0, (int) $wallet->bonus_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'source' => 'subscription_fee_bonus',
            'amount' => 500,
        ]);
    }

    public function test_subscription_not_renewed_without_funds(): void
    {
        $driver = $this->makeDriver(0, 0, 1);
        $ride = $this->makeOngoingRide($driver);

        app(\App\Services\RideCompletionService::class)->complete($ride);

        // Zéro dette : pack épuisé, rien débité, compteur à 0
        $this->assertSame(0, (int) DB::table('driver_profiles')->where('user_id', $driver->id)->value('subscription_remaining_rides'));

        $wallet = $this->wallet($driver);
        $this->assertSame(0, (int) $wallet->balance);
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'source' => 'subscription_fee',
        ]);
    }
}
