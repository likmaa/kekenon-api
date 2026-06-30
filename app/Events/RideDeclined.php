<?php

namespace App\Events;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideDeclined implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Ride $ride, public User $driver)
    {
        $this->ride->loadMissing('rider');
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.declined';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'driver' => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'phone' => $this->driver->phone,
            ],
            'pickup_address' => $this->ride->pickup_address,
            'dropoff_address' => $this->ride->dropoff_address,
            'declined_at' => now()->toIso8601String(),
            'rider_id' => $this->ride->rider_id,
        ];
    }
}

