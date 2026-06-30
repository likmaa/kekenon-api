<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class RideAccepted implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public float $createdAtTs;

    public function __construct(public Ride $ride)
    {
        $this->createdAtTs = microtime(true);
        \Log::info("RideAccepted Created", [
            'rideId' => $this->ride?->id,
            'ts' => $this->createdAtTs
        ]);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rider.' . $this->ride->rider_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.accepted';
    }

    public function broadcastWith(): array
    {
        $driver = $this->ride->driver;

        return [
            'rideId' => $this->ride->id,
            'driver' => $driver ? [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'vehicle_number' => $driver->vehicle_number,
                'photo' => $driver->photo,
            ] : null,
            'debug_latency' => [
                'created_at' => $this->createdAtTs,
                'broadcast_at' => microtime(true),
                'delay' => microtime(true) - $this->createdAtTs
            ]
        ];
    }
}

