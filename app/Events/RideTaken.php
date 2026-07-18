<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Une course « requested » vient d'être prise par un chauffeur.
 * Diffusé à TOUS les chauffeurs (canal presence-drivers) pour retirer
 * instantanément l'offre de leur écran. Le perdant d'une course-course
 * l'utilise pour afficher « course perdue » plutôt qu'une erreur.
 *
 * Volontairement léger : aucune donnée personnelle (juste l'id de course
 * et l'id du gagnant), puisque le canal est partagé par tous les chauffeurs.
 */
class RideTaken implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $rideId,
        public int $winnerDriverId,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('drivers'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.taken';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->rideId,
            'winnerDriverId' => $this->winnerDriverId,
        ];
    }
}
