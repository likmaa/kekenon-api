<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideStopUpdated implements ShouldBroadcast
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
        return 'ride.stop.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'stop_started_at' => $this->ride->stop_started_at ? $this->ride->stop_started_at->toIso8601String() : null,
            'total_stop_duration_s' => (int) $this->ride->total_stop_duration_s,
        ];
    }
}
