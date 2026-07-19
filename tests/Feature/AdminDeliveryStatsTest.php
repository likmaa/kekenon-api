<?php

namespace Tests\Feature;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDeliveryStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_stats_keep_deliveries_separate_from_courses(): void
    {
        $now = now();
        $passenger = User::factory()->create(['role' => 'passenger']);

        Ride::create([
            'rider_id' => $passenger->id,
            'service_type' => 'course',
            'status' => 'completed',
            'fare_amount' => 1000,
            'distance_m' => 2000,
            'created_at' => $now,
            'started_at' => $now->copy()->subMinutes(20),
            'completed_at' => $now,
        ]);
        Ride::create([
            'rider_id' => $passenger->id,
            'service_type' => 'livraison',
            'status' => 'completed',
            'fare_amount' => 2400,
            'distance_m' => 5000,
            'created_at' => $now,
            'started_at' => $now->copy()->subMinutes(35),
            'completed_at' => $now,
        ]);
        Ride::create(['rider_id' => $passenger->id, 'service_type' => 'course', 'status' => 'ongoing', 'created_at' => $now]);
        Ride::create(['rider_id' => $passenger->id, 'service_type' => 'livraison', 'status' => 'requested', 'created_at' => $now]);
        Ride::create([
            'rider_id' => $passenger->id,
            'service_type' => 'course',
            'status' => 'cancelled',
            'created_at' => $now,
            'cancelled_at' => $now,
        ]);
        Ride::create([
            'rider_id' => $passenger->id,
            'service_type' => 'livraison',
            'status' => 'cancelled',
            'created_at' => $now,
            'cancelled_at' => $now,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/admin/stats/dispatch')
            ->assertOk()
            ->assertJsonPath('dispatch.cancelled_rides', 1)
            ->assertJsonPath('courses.completed_count', 1)
            ->assertJsonPath('courses.active_count', 1)
            ->assertJsonPath('courses.cancelled_count', 1)
            ->assertJsonPath('courses.avg_distance_m', 2000)
            ->assertJsonPath('courses.avg_fare_amount', 1000)
            ->assertJsonPath('deliveries.completed_count', 1)
            ->assertJsonPath('deliveries.active_count', 1)
            ->assertJsonPath('deliveries.cancelled_count', 1)
            ->assertJsonPath('deliveries.avg_distance_m', 5000)
            ->assertJsonPath('deliveries.avg_fare_amount', 2400);

        $this->getJson('/api/admin/stats/overview')
            ->assertOk()
            ->assertJsonPath('today_completed_rides', 1)
            ->assertJsonPath('today_revenue.amount', 3400);

        $trend = $this->getJson('/api/admin/stats/trends?granularity=day')->assertOk();
        $lastPoint = collect($trend->json('series'))->last();
        $this->assertSame(1, $lastPoint['rides']);
        $this->assertSame(1, $lastPoint['deliveries']);
    }
}
