<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RideBid extends Model
{
    use HasFactory;

    protected $table = 'ride_bids';

    protected $fillable = [
        'ride_id',
        'sender_id',
        'proposed_fare',
        'status',
    ];

    protected $casts = [
        'proposed_fare' => 'integer',
    ];

    // ─── Relations ────────────────────────────────────────────────

    public function ride()
    {
        return $this->belongsTo(Ride::class, 'ride_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForRide($query, int $rideId)
    {
        return $query->where('ride_id', $rideId);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    /**
     * Données publiques du bid.
     */
    public function toPublicArray(): array
    {
        $sender = $this->sender;
        $profile = $sender?->driverProfile;

        return [
            'id'            => $this->id,
            'ride_id'       => $this->ride_id,
            'sender_id'     => $this->sender_id,
            'proposed_fare' => $this->proposed_fare,
            'status'        => $this->status,
            'created_at'    => $this->created_at?->toIso8601String(),
            'sender'        => [
                'id'              => $sender?->id,
                'name'            => $sender?->name,
                'phone'           => $sender?->phone,
                'avatar_url'      => $sender?->avatar_url,
                'role'            => $sender?->role, // 'passenger' or 'driver'
                'rating'          => $sender?->rating_avg,
                'vehicle_make'    => $profile?->vehicle_make,
                'vehicle_model'   => $profile?->vehicle_model,
                'vehicle_plate'   => $profile?->vehicle_plate,
                'vehicle_color'   => $profile?->vehicle_color,
            ],
        ];
    }
}
