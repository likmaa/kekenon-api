<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmed implements ShouldBroadcastNow
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
            new PrivateChannel('driver.' . $this->ride->driver_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.confirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'status' => $this->ride->payment_status,
            'amount' => $this->ride->fare_amount,
        ];
    }
}
