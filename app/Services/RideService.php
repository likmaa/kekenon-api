<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\FcmToken;
use App\Events\RideRequested;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RideService
{
    protected $fcm;

    public function __construct(FcmService $fcm)
    {
        $this->fcm = $fcm;
    }

    /**
     * Requête de base : chauffeurs approuvés, en ligne, géolocalisés récemment.
     */
    private function baseDriverTokenQuery(): Builder
    {
        return FcmToken::query()
            ->join('users', 'users.id', '=', 'fcm_tokens.user_id')
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('users.role', 'driver')
            ->where('users.is_online', true)
            ->where('users.is_active', true)
            ->where('driver_profiles.status', 'approved')
            ->whereNotNull('users.last_lat')
            ->whereNotNull('users.last_lng')
            ->where('users.last_location_at', '>=', now()->subMinutes(45));
    }

    /**
     * Applique le filtre lignes TIC si la table driver_lines est utilisée.
     */
    private function applyTicLineFilter(Builder $q, Ride $ride): Builder
    {
        $driverLineConfigured = DB::table('driver_lines')->exists();
        if ($ride->service_type !== 'deplacement' || ! $ride->line_id || ! $driverLineConfigured) {
            return $q;
        }

        $lineIds = array_values(array_filter([(int) $ride->line_id, $ride->line_2_id ? (int) $ride->line_2_id : null]));
        $allowedUserIds = DB::table('driver_lines')
            ->whereIn('line_id', $lineIds)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($allowedUserIds === []) {
            Log::info('FCM: aucun chauffeur affecté aux lignes ['.implode(',', $lineIds)."] pour la course #{$ride->id}.");

            return $q->whereRaw('1 = 0');
        }

        return $q->whereIn('users.id', $allowedUserIds);
    }

    /**
     * Notifie les chauffeurs à proximité (FCM + Pusher).
     */
    public function notifyNearbyDrivers(Ride $ride)
    {
        try {
            rescue(fn () => broadcast(new RideRequested($ride)));
        } catch (\Exception $e) {
            Log::error('Pusher Broadcast Error: '.$e->getMessage());
        }

        try {
            $radius = (float) config('app.search_radius_km', 10.0);
            $earthRadiusKm = 6371.0;

            $q = $this->applyTicLineFilter($this->baseDriverTokenQuery(), $ride);

            if ($ride->pickup_lat && $ride->pickup_lng) {
                $lat = $ride->pickup_lat;
                $lng = $ride->pickup_lng;

                $distanceFormula = "(
                    {$earthRadiusKm} * 2 * ASIN(
                        SQRT(
                            POWER(SIN(RADIANS({$lat} - users.last_lat) / 2), 2) +
                            COS(RADIANS({$lat})) * COS(RADIANS(users.last_lat)) *
                            POWER(SIN(RADIANS({$lng} - users.last_lng) / 2), 2)
                        )
                    )
                )";

                $nearbyDriverTokens = $q
                    ->whereRaw("{$distanceFormula} <= ?", [$radius])
                    ->pluck('token')
                    ->unique()
                    ->toArray();
            } else {
                Log::info("FCM Fallback: No coordinates for Ride #{$ride->id}, notifying drivers (within filters).");

                $nearbyDriverTokens = $q
                    ->pluck('token')
                    ->unique()
                    ->toArray();
            }

            if (! empty($nearbyDriverTokens)) {
                $this->fcm->sendToTokens(
                    $nearbyDriverTokens,
                    $ride->service_type === 'livraison' ? 'Nouvelle livraison !' : 'Nouvelle course !',
                    ($ride->service_type === 'livraison' ? 'Une livraison' : 'Une course').' à '.number_format($ride->fare_amount, 0, ',', ' ').' FCFA est disponible à proximité.',
                    [
                        'ride_id' => (string) $ride->id,
                        'type' => 'new_ride',
                        'service_type' => (string) ($ride->service_type ?? 'course'),
                        'pickup_address' => (string) $ride->pickup_address,
                        'fare' => (string) $ride->fare_amount,
                        'rider_voice_note' => $ride->rider_voice_note
                            ? mb_substr((string) $ride->rider_voice_note, 0, 200)
                            : '',
                        'rider_voice_audio_path' => $ride->rider_voice_audio_path
                            ? (string) $ride->rider_voice_audio_path
                            : '',
                    ],
                    dataOnly: true, // app chauffeur affiche elle-même (handlers FCM)
                );
                Log::info('FCM Sent to '.count($nearbyDriverTokens).' drivers for Ride #'.$ride->id);
            }
        } catch (\Exception $e) {
            Log::error('FCM Driver Notification Error: '.$e->getMessage());
        }
    }
}
