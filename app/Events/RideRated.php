<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideRated implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Ride $ride, public int $stars)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('driver.' . $this->ride->driver_id),
            new PrivateChannel('ride.' . $this->ride->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.rated';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'rating' => (int) $this->stars,
            'tip_amount' => (int) $this->ride->tip_amount,
        ];
    }
}
