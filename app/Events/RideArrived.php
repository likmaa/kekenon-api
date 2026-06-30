<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class RideArrived implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public int $rideId,
        public int $riderId,
        public string $arrivedAt
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('ride.' . $this->rideId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.arrived';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->rideId,
            'arrived_at' => $this->arrivedAt,
        ];
    }
}