<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideRequested implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public float $createdAtTs;

    public function __construct(public Ride $ride)
    {
        $this->createdAtTs = microtime(true);
        \Log::info("RideRequested Created", [
            'rideId' => $this->ride?->id,
            'ts' => $this->createdAtTs
        ]);
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('drivers'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'pickup' => [
                'lat' => $this->ride->pickup_lat,
                'lng' => $this->ride->pickup_lng,
                'address' => $this->ride->pickup_address,
            ],
            'dropoff' => [
                'lat' => $this->ride->dropoff_lat,
                'lng' => $this->ride->dropoff_lng,
                'address' => $this->ride->dropoff_address,
            ],
            'fare' => (int) ($this->ride->fare_amount ?? 0),
            'pricing_mode' => $this->ride->pricing_mode ?? 'fixed',
            'service_type' => $this->ride->service_type ?? 'course',
            'rider_id' => $this->ride->rider_id,
            'passenger' => [
                'name' => $this->ride->passenger_name,
                'phone' => $this->ride->passenger_phone,
            ],
            'rider_voice_note' => $this->ride->rider_voice_note,
            'rider_voice_audio_path' => $this->ride->rider_voice_audio_path,
            'debug_latency' => [
                'created_at' => $this->createdAtTs,
                'broadcast_at' => microtime(true),
                'delay' => microtime(true) - $this->createdAtTs
            ]
        ];
    }
}

