<?php

namespace App\Events;

use App\Models\RideBid;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasté sur le channel privé du chauffeur gagnant quand son offre est acceptée.
 * Channel : private-driver.{driver_id}
 * Event   : bid.accepted
 */
class BidAccepted implements ShouldBroadcast
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
        return 'bid.accepted';
    }

    public function broadcastWith(): array
    {
        $ride = $this->bid->ride;

        return [
            'rideId' => $ride->id,
            'fare'   => $this->bid->proposed_fare,
            'ride'   => [
                'id'             => $ride->id,
                'status'         => $ride->status,
                'fare_amount'    => $ride->fare_amount,
                'pickup'         => [
                    'lat'     => $ride->pickup_lat,
                    'lng'     => $ride->pickup_lng,
                    'address' => $ride->pickup_address,
                ],
                'dropoff'        => [
                    'lat'     => $ride->dropoff_lat,
                    'lng'     => $ride->dropoff_lng,
                    'address' => $ride->dropoff_address,
                ],
                'passenger_name'  => $ride->passenger_name,
                'passenger_phone' => $ride->passenger_phone,
            ],
        ];
    }
}
