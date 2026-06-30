<?php

namespace App\Events;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class RideCancelled implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Ride $ride,
        public string $cancelledBy,
        public ?User $actor = null
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.alerts'),
        ];

        if ($this->ride->id) {
            $channels[] = new PrivateChannel('ride.' . $this->ride->id);
        }

        if ($this->ride->rider_id) {
            $channels[] = new PrivateChannel('rider.' . $this->ride->rider_id);
        }

        if ($this->ride->status === 'cancelled') {
            $channels[] = new PresenceChannel('drivers');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'cancelled_by' => $this->cancelledBy,
            'actor' => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'phone' => $this->actor->phone,
            ] : null,
            'status' => $this->ride->status,
            'reason' => $this->ride->cancellation_reason,
            'cancelled_at' => optional($this->ride->cancelled_at)->toIso8601String(),
            'pickup_address' => $this->ride->pickup_address,
            'dropoff_address' => $this->ride->dropoff_address,
        ];
    }
}

