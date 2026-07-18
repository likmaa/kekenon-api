<?php

namespace Tests\Feature;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PawaPayFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Jeton factice : PawaPayService exige une valeur non vide.
        config()->set('services.pawapay.api_token', 'test-token');
        config()->set('services.pawapay.sandbox', true);
    }

    /** Simule PawaPay : dépôt accepté puis statut COMPLETED à la relecture. */
    private function fakePawaPayCompleted(): void
    {
        Http::fake([
            '*/v2/deposits/*' => Http::response([
                'status' => 'FOUND',
                'data' => ['status' => 'COMPLETED'],
            ], 200),
            '*/v2/deposits' => Http::response([
                'depositId' => 'srv-generated',
                'status' => 'ACCEPTED',
                'created' => now()->toIso8601String(),
            ], 200),
        ]);
    }

    public function test_wallet_topup_initiates_then_credits_wallet_on_callback(): void
    {
        $this->fakePawaPayCompleted();
        $user = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($user);

        // 1. Initiation du rechargement
        $res = $this->postJson('/api/passenger/wallet/topup/checkout', [
            'amount' => 5000,
            'phone' => '0161000000',
            'provider' => 'MTN_MOMO_BEN',
        ]);

        $res->assertOk()->assertJson(['ok' => true, 'status' => 'pending']);
        $reference = $res->json('reference');
        $depositId = $res->json('deposit_id');
        $this->assertNotEmpty($reference);
        $this->assertNotEmpty($depositId);

        $this->assertDatabaseHas('topup_requests', [
            'reference' => $reference,
            'provider_ref' => $depositId,
            'status' => 'pending',
            'amount' => 5000,
        ]);

        // 2. Callback PawaPay (public) → crédit du portefeuille
        $this->postJson('/api/pawapay/deposits/callback', ['depositId' => $depositId])
            ->assertOk();

        $this->assertDatabaseHas('topup_requests', ['reference' => $reference, 'status' => 'completed']);
        $walletId = DB::table('wallets')->where('user_id', $user->id)->value('id');
        $this->assertSame(5000, (int) DB::table('wallets')->where('id', $walletId)->value('balance'));
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $walletId,
            'source' => 'topup_pawapay',
            'amount' => 5000,
        ]);
    }

    public function test_callback_is_idempotent(): void
    {
        $this->fakePawaPayCompleted();
        $user = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($user);

        $depositId = $this->postJson('/api/passenger/wallet/topup/checkout', [
            'amount' => 3000, 'phone' => '0161000000', 'provider' => 'MOOV_BEN',
        ])->json('deposit_id');

        // Deux callbacks : le solde ne doit être crédité qu'une fois.
        $this->postJson('/api/pawapay/deposits/callback', ['depositId' => $depositId])->assertOk();
        $this->postJson('/api/pawapay/deposits/callback', ['depositId' => $depositId])->assertOk();

        $balance = (int) DB::table('wallets')->where('user_id', $user->id)->value('balance');
        $this->assertSame(3000, $balance, 'Le double callback ne doit pas créditer deux fois.');
    }

    public function test_topup_rejects_unknown_provider(): void
    {
        $user = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($user);

        $this->postJson('/api/passenger/wallet/topup/checkout', [
            'amount' => 5000, 'phone' => '0161000000', 'provider' => 'ORANGE_BEN',
        ])->assertStatus(422);
    }

    public function test_ride_mobile_money_checkout_then_payment_confirmed(): void
    {
        $this->fakePawaPayCompleted();
        $passenger = User::factory()->create(['role' => 'passenger']);
        $driver = User::factory()->create(['role' => 'driver']);
        Sanctum::actingAs($passenger);

        $ride = Ride::create([
            'rider_id' => $passenger->id,
            'driver_id' => $driver->id,
            'status' => 'completed',
            'payment_method' => 'mobile_money',
            'payment_status' => 'pending',
            'fare_amount' => 1500,
            'driver_earnings_amount' => 1500,
            'currency' => 'XOF',
            'pickup_lat' => 6.36, 'pickup_lng' => 2.42,
            'dropoff_lat' => 6.37, 'dropoff_lng' => 2.43,
            'vehicle_type' => 'standard',
        ]);

        // 1. Checkout course
        $res = $this->postJson("/api/passenger/rides/{$ride->id}/checkout", [
            'phone' => '0161000000',
            'provider' => 'MTN_MOMO_BEN',
        ]);
        $res->assertOk()->assertJson(['ok' => true]);
        $depositId = $res->json('deposit_id');

        $ride->refresh();
        $this->assertSame($depositId, $ride->external_reference);
        $this->assertSame('pending', $ride->payment_status);

        // 2. Callback → paiement enregistré + gains crédités au chauffeur
        $this->postJson('/api/pawapay/deposits/callback', ['depositId' => $depositId])->assertOk();

        $ride->refresh();
        $this->assertSame('completed', $ride->payment_status);
        $this->assertDatabaseHas('payments', [
            'ride_id' => $ride->id,
            'status' => 'succeeded',
            'amount' => 1500,
        ]);
        $driverBalance = (int) DB::table('wallets')->where('user_id', $driver->id)->value('balance');
        $this->assertSame(1500, $driverBalance);
    }

    public function test_failed_deposit_marks_topup_failed_without_crediting(): void
    {
        Http::fake([
            '*/v2/deposits/*' => Http::response(['status' => 'FOUND', 'data' => ['status' => 'FAILED']], 200),
            '*/v2/deposits' => Http::response(['depositId' => 'x', 'status' => 'ACCEPTED'], 200),
        ]);
        $user = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($user);

        $depositId = $this->postJson('/api/passenger/wallet/topup/checkout', [
            'amount' => 4000, 'phone' => '0161000000', 'provider' => 'MTN_MOMO_BEN',
        ])->json('deposit_id');

        $this->postJson('/api/pawapay/deposits/callback', ['depositId' => $depositId])->assertOk();

        $this->assertDatabaseHas('topup_requests', ['provider_ref' => $depositId, 'status' => 'failed']);
        $balance = (int) (DB::table('wallets')->where('user_id', $user->id)->value('balance') ?? 0);
        $this->assertSame(0, $balance);
    }
}
