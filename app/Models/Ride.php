<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ride extends Model
{
    use HasFactory;

    protected $table = 'rides';

    protected $fillable = [
        'rider_id',
        'driver_id',
        'status',
        'fare_amount',
        'commission_amount',
        'driver_earnings_amount',
        'currency',
        'distance_m',
        'approach_distance_m',
        'duration_s',
        'pickup_lat',
        'pickup_lng',
        'dropoff_lat',
        'dropoff_lng',
        'pickup_address',
        'dropoff_address',
        'offered_driver_id',
        'declined_driver_ids',
        'passenger_name',
        'passenger_phone',
        'rider_voice_note',
        'rider_voice_audio_path',
        'accepted_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'vehicle_type',
        'total_stop_duration_s',
        'stop_started_at',
        'arrived_at',
        'tip_amount',
        'payment_method',
        'service_type',
        'line_id',
        'line_2_id',
        'recipient_name',
        'recipient_phone',
        'package_description',
        'package_weight',
        'is_fragile',
        'luggage_count',
        'has_baggage',
        'payment_status',
        'payment_link',
        'external_reference',
        'promo_code_id',
        'original_fare_amount',
        'discount_amount',
        // Bidding / Négociation
        'pricing_mode',
        'negotiated_fare',
        'bid_accepted_driver_id',
        'negotiation_confirmed_at',
    ];


    protected $casts = [
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'stop_started_at' => 'datetime',
        'arrived_at' => 'datetime',
        'negotiation_confirmed_at' => 'datetime',
        'declined_driver_ids' => 'array',
        'breakdown' => 'array',
        'has_baggage' => 'boolean',
    ];

    /**
     * Distance d'approche estimée (mètres) : position du chauffeur → point de prise en charge,
     * haversine × 1.3 (même coefficient de détour que l'estimation de course).
     * Retourne null si une coordonnée manque.
     */
    public static function estimateApproachDistanceM($driverLat, $driverLng, $pickupLat, $pickupLng): ?int
    {
        if ($driverLat === null || $driverLng === null || $pickupLat === null || $pickupLng === null) {
            return null;
        }

        $earthRadius = 6371000;
        $dLat = deg2rad((float) $pickupLat - (float) $driverLat);
        $dLng = deg2rad((float) $pickupLng - (float) $driverLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $driverLat)) * cos(deg2rad((float) $pickupLat)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round($earthRadius * $c * 1.3);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function rating()
    {
        return $this->hasOne(Rating::class, 'ride_id');
    }

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }
}
