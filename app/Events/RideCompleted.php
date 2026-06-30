<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class RideCompleted implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Ride $ride)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rider.' . $this->ride->rider_id),
            new PrivateChannel('ride.' . $this->ride->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'status' => $this->ride->status,
            'fare_amount' => $this->ride->fare_amount,
            'distance_m' => $this->ride->distance_m,
            'breakdown' => $this->ride->breakdown ?? null,
            'payment_method' => $this->ride->payment_method,
            'payment_link' => $this->ride->payment_link,
        ];
    }
}
