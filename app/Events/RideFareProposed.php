<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Négociation verbale : le chauffeur propose un prix convenu au passager.
 * Diffusé sur le canal privé du passager pour mettre à jour, en direct, le
 * montant affiché sur son écran de confirmation (avant qu'il n'accepte).
 */
class RideFareProposed implements ShouldBroadcastNow
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
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.fare.proposed';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'fare' => (int) ($this->ride->negotiated_fare ?? $this->ride->fare_amount ?? 0),
        ];
    }
}
