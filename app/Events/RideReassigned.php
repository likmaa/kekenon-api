<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Assignation/réassignation manuelle d'une course par le support.
 *
 * Diffusé sur le canal personnel des deux chauffeurs concernés :
 *  - l'ancien (s'il existe) doit libérer son écran de course en cours ;
 *  - le nouveau doit charger la course immédiatement, sans redémarrer l'app.
 * Chaque app compare son propre id à new_driver_id / old_driver_id.
 */
class RideReassigned implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Ride $ride,
        public int $newDriverId,
        public ?int $oldDriverId = null,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('driver.' . $this->newDriverId)];

        if ($this->oldDriverId && $this->oldDriverId !== $this->newDriverId) {
            $channels[] = new PrivateChannel('driver.' . $this->oldDriverId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.reassigned';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'new_driver_id' => $this->newDriverId,
            'old_driver_id' => $this->oldDriverId,
            'status' => $this->ride->status,
            'pickup_address' => $this->ride->pickup_address,
            'dropoff_address' => $this->ride->dropoff_address,
        ];
    }
}
