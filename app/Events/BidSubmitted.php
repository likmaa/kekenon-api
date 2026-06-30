<?php

namespace App\Events;

use App\Models\RideBid;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasté sur le channel privé du passager quand un zem soumet une offre.
 * Channel : private-passenger.{rider_id}
 * Event   : bid.submitted
 */
class BidSubmitted implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public RideBid $bid)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('ride.' . $this->bid->ride_id),
        ];
    }



    public function broadcastAs(): string
    {
        return 'bid.submitted';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->bid->ride_id,
            'bid'    => $this->bid->toPublicArray(),
        ];
    }
}
