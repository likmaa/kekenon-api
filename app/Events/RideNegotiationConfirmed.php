<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Négociation verbale validée (ou refusée) par le passager.
 * Diffusé sur le canal privé du chauffeur qui a pris la course.
 *   - confirmed = true  → le passager a confirmé : « Aller chercher mon client » s'active.
 *   - confirmed = false → le passager a refusé : la course est relâchée, retour à l'accueil.
 */
class RideNegotiationConfirmed implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Ride $ride,
        public int $driverId,
        public bool $confirmed,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('driver.' . $this->driverId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.negotiation.confirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'confirmed' => $this->confirmed,
            'fare' => (int) ($this->ride->negotiated_fare ?? $this->ride->fare_amount ?? 0),
        ];
    }
}
