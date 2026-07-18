<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Le chauffeur part chercher le passager (« Aller chercher mon client »).
 * Diffusé sur le canal de la COURSE (private-ride.{id}) — celui que l'écran de
 * suivi (DriverTracking) écoute déjà : côté client, un bouton « Suivre mon
 * chauffeur sur la carte » apparaît pour visualiser le déplacement en temps réel.
 */
class RideEnroute implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $rideId,
        public int $riderId,
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
        return 'ride.enroute';
    }

    public function broadcastWith(): array
    {
        return ['rideId' => $this->rideId];
    }
}
