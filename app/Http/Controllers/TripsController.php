<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use App\Events\DriverLocationUpdated;
use App\Events\RideAccepted;
use App\Events\RideTaken;
use App\Events\RideNegotiationConfirmed;
use App\Events\RideFareProposed;
use App\Events\RideEnroute;
use App\Events\RideRequested;
use App\Events\RideDeclined;
use App\Events\RideCancelled;
use App\Events\RideStarted;
use App\Events\RideCompleted;
use App\Events\RideStopUpdated;
use App\Events\RideArrived;
use App\Models\FcmToken;
use App\Services\FcmService;
use App\Services\PassengerBonusService;
use App\Services\RideService;
use App\Services\DeliveryPricingService;

use App\Models\PricingSetting;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TripsController extends Controller
{
    protected $rideService;
    protected $passengerBonusService;

    public function __construct(
        RideService $rideService,
        PassengerBonusService $passengerBonusService,
        protected DeliveryPricingService $deliveryPricing,
    )
    {
        $this->rideService = $rideService;
        $this->passengerBonusService = $passengerBonusService;
    }

    protected function apiError(string $code, string $message, int $status, array $errors = []): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    protected function apiForbidden(string $message = 'Forbidden'): \Illuminate\Http\JsonResponse
    {
        return $this->apiError('FORBIDDEN', $message, 403);
    }

    protected function apiInvalidState(string $message = 'Invalid state'): \Illuminate\Http\JsonResponse
    {
        return $this->apiError('INVALID_STATE', $message, 422);
    }


    /**
     * Filtre portable (MySQL + SQLite) : le chauffeur n’a pas décliné la course.
     * Évite JSON_CONTAINS qui n’existe pas sur SQLite (souvent utilisé en local).
     */
    protected function whereDriverHasNotDeclinedRide(Builder $query, User $driver): void
    {
        $driverId = (int) $driver->id;
        $query->where(function ($sub) use ($driverId) {
            $sub->whereNull('declined_driver_ids')
                ->orWhereJsonDoesntContain('declined_driver_ids', $driverId);
        });
    }

    /**
     * Limite les courses simultanées passager : au plus une recherche (`requested`) à la fois,
     * et au plus 2 courses « vivantes » (requested + acceptation + en cours).
     */
    protected function passengerActiveRideConcurrencyResponse(?User $user): ?\Illuminate\Http\JsonResponse
    {
        if (app()->environment('testing')) {
            return null;
        }
        if (! $user || ! $user->isPassenger()) {
            return null;
        }

        $activeStatuses = ['requested', 'accepted', 'arrived', 'pickup', 'started', 'ongoing'];

        if (Ride::where('rider_id', $user->id)->where('status', 'requested')->exists()) {
            $requestedRide = Ride::where('rider_id', $user->id)
                ->where('status', 'requested')
                ->orderByDesc('id')
                ->first();

            return response()->json([
                'message' => 'Une recherche de chauffeur est déjà en cours. Patientez ou annulez-la avant d’en lancer une autre.',
                'id' => $requestedRide?->id,
                'status' => 'requested',
            ], 422);
        }

        $activeCount = Ride::where('rider_id', $user->id)->whereIn('status', $activeStatuses)->count();
        if ($activeCount >= 2) {
            return response()->json([
                'message' => 'Vous avez déjà deux courses en cours (maximum autorisé).',
            ], 422);
        }

        return null;
    }

    public function estimate(Request $request)
    {
        $data = $request->validate([
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff.lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required', 'numeric', 'between:-180,180'],
            'distance_m' => ['required', 'numeric', 'min:1', 'max:600000'],
            'duration_s' => ['required', 'numeric', 'min:1', 'max:86400'],
            'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
            'luggage_count' => ['nullable', 'integer', 'min:0', 'max:3'],
            'service_type' => ['nullable', 'string', 'in:course,livraison'],
            'package_size' => ['nullable', 'string', 'in:small,medium,large'],
            'package_weight' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'is_fragile' => ['nullable', 'boolean'],
        ]);

        $vehicleType = $request->input('vehicle_type', 'standard');
        $luggageCount = (int) $request->input('luggage_count', 0);

        $distance = (float) $request->input('distance_m');
        $duration = (float) $request->input('duration_s');

        $price = $this->computeFareFromDistance(
            (float) $distance,
            $vehicleType,
            $luggageCount,
            (float) $request->input('pickup.lat'),
            (float) $request->input('pickup.lng'),
            (float) $request->input('dropoff.lat'),
            (float) $request->input('dropoff.lng'),
            (int) $duration
        );
        $deliveryBreakdown = ['size_fee' => 0, 'fragile_fee' => 0, 'weight_fee' => 0, 'total' => 0];
        if ($request->input('service_type') === 'livraison') {
            $deliveryBreakdown = $this->deliveryPricing->breakdown(
                $request->input('package_size'),
                $request->input('package_weight'),
                (bool) $request->boolean('is_fragile')
            );
            $price += $deliveryBreakdown['total'];
        }

        $user = Auth::guard('sanctum')->user();
        $eligibleForFirstRideDiscount = $this->passengerBonusService->isEligible($user);
        $businessModel = app(\App\Services\EconomicModelService::class)->get();

        return response()->json([
            'price' => $price,
            'passenger_app_fee' => $businessModel['passenger_app_fee'],
            'estimated_total_with_app_fee' => $price + $businessModel['passenger_app_fee'],
            'currency' => 'XOF',
            'eta_s' => (int) round($duration),
            'distance_m' => (int) round($distance),
            'eligible_first_ride_discount' => $eligibleForFirstRideDiscount,
            'delivery_fee_breakdown' => $deliveryBreakdown,
        ]);
    }

    public function estimateFromCoords(Request $request)
    {
        $data = $request->validate([
            'pickup.lat'   => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng'   => ['required', 'numeric', 'between:-180,180'],
            'dropoff.lat'  => ['required', 'numeric', 'between:-90,90'],
            'dropoff.lng'  => ['required', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
            'luggage_count' => ['nullable', 'integer', 'min:0', 'max:3'],
            'service_type' => ['nullable', 'string', 'in:course,livraison'],
            'package_size' => ['nullable', 'string', 'in:small,medium,large'],
            'package_weight' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'is_fragile' => ['nullable', 'boolean'],
        ]);

        $vehicleType  = $request->input('vehicle_type', 'standard');
        $luggageCount = (int) $request->input('luggage_count', 0);

        $pickLat  = (float) $request->input('pickup.lat');
        $pickLng  = (float) $request->input('pickup.lng');
        $dropLat  = (float) $request->input('dropoff.lat');
        $dropLng  = (float) $request->input('dropoff.lng');

        // Utilise OSRM public pour récupérer distance et durée + géométrie
        $url = 'https://router.project-osrm.org/route/v1/driving/'
            . $pickLng . ',' . $pickLat . ';'
            . $dropLng . ',' . $dropLat
            . '?overview=full&geometries=geojson';

        $distance = null;
        $duration = null;
        $geometry = null;
        $source   = 'osrm';

        try {
            $resp = Http::timeout(4)->get($url);

            if ($resp->ok()) {
                $json  = $resp->json();
                $route = $json['routes'][0] ?? null;
                if ($route) {
                    $distance = (float) ($route['distance'] ?? 0); // mètres
                    $duration = (float) ($route['duration'] ?? 0); // secondes
                    $geometry = $route['geometry'] ?? null;
                } else {
                    \Log::warning('OSRM returned no route', ['url' => $url]);
                }
            } else {
                \Log::error('OSRM Routing failed for ride estimate', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                    'url'    => $url,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('OSRM Routing timeout or exception', [
                'error' => $e->getMessage(),
                'url'   => $url,
            ]);
        }

        // FALLBACK-01 : Si OSRM est indisponible ou ne renvoie aucun itinéraire,
        // on calcule une estimation de secours par la formule de Haversine (distance
        // orthodromique = distance à vol d'oiseau) multipliée par un coefficient de
        // détour de 1.3, qui est une approximation raisonnable pour les réseaux
        // routiers urbains d'Afrique de l'Ouest.
        // L'application reste ainsi fonctionnelle même si le service de cartographie est en panne.
        if ($distance === null) {
            $source   = 'fallback';
            $distance = $this->haversineDistanceMeters($pickLat, $pickLng, $dropLat, $dropLng) * 1.3;
            // Estimation de durée : on suppose une vitesse moyenne de 30 km/h en ville
            $duration = ($distance / 1000.0) * (3600 / 30);
        }

        $totalPrice = $this->computeFareFromDistance(
            $distance,
            $vehicleType,
            $luggageCount,
            $pickLat,
            $pickLng,
            $dropLat,
            $dropLng,
            (int) round($duration ?? 0)
        );
        $deliveryBreakdown = ['size_fee' => 0, 'fragile_fee' => 0, 'weight_fee' => 0, 'total' => 0];
        if ($request->input('service_type') === 'livraison') {
            $deliveryBreakdown = $this->deliveryPricing->breakdown(
                $request->input('package_size'),
                $request->input('package_weight'),
                (bool) $request->boolean('is_fragile')
            );
            $totalPrice += $deliveryBreakdown['total'];
        }

        $user = Auth::guard('sanctum')->user();
        $eligibleForFirstRideDiscount = $this->passengerBonusService->isEligible($user);
        $businessModel = app(\App\Services\EconomicModelService::class)->get();

        return response()->json([
            'price'      => $totalPrice,
            'passenger_app_fee' => $businessModel['passenger_app_fee'],
            'estimated_total_with_app_fee' => $totalPrice + $businessModel['passenger_app_fee'],
            'currency'   => 'XOF',
            'eta_s'      => (int) round($duration),
            'distance_m' => (int) round($distance),
            'geometry'   => $geometry,
            'source'     => $source,
            'eligible_first_ride_discount' => $eligibleForFirstRideDiscount,
            'delivery_fee_breakdown' => $deliveryBreakdown,
        ]);
    }



    public function create(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();

        // Vérification du solde minimum de 100 F pour commander
        $passengerWalletBalance = (float) DB::table('wallets')->where('user_id', $user->id)->value('balance');
        if ($passengerWalletBalance < 100) {
            return $this->apiError(
                'INSUFFICIENT_WALLET_BALANCE',
                'Votre solde de portefeuille doit être d\'au moins 100 F pour commander une course.',
                422
            );
        }

        $contentType = (string) $request->header('Content-Type', '');
        $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

        $v = null;
        if ($isMultipart) {
            $v = $request->validate([
                'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
                'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
                'pickup_label' => ['nullable', 'string', 'max:255'],
                'dropoff_lat' => ['required_if:service_type,livraison', 'nullable', 'numeric', 'between:-90,90'],
                'dropoff_lng' => ['required_if:service_type,livraison', 'nullable', 'numeric', 'between:-180,180'],
                'dropoff_label' => ['nullable', 'string', 'max:255'],
                'distance_m' => ['nullable', 'numeric', 'min:0', 'max:600000'],
                'duration_s' => ['nullable', 'numeric', 'min:0', 'max:86400'],
                'order_mode' => ['nullable', 'string', 'in:distance,duration'],
                'duration_hours' => ['nullable', 'integer', 'min:1'],
                'price' => ['nullable', 'numeric', 'min:1'],
                'passenger_name' => ['nullable', 'string', 'max:255'],
                'passenger_phone' => ['nullable', 'string', 'max:255'],
                'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
                'has_baggage' => ['nullable'],
                'luggage_count' => ['nullable', 'integer', 'min:0', 'max:5'],
                'payment_method' => ['nullable', 'string', 'in:cash,wallet,card,qr,mobile_money,bonus'],
                'service_type' => ['nullable', 'string', 'in:course,livraison'],
                'recipient_name' => ['required_if:service_type,livraison', 'nullable', 'string', 'min:2', 'max:255'],
                'recipient_phone' => ['required_if:service_type,livraison', 'nullable', 'string', 'max:30', 'regex:/^[0-9+ ]{8,30}$/'],
                'package_description' => ['required_if:service_type,livraison', 'nullable', 'string', 'min:2', 'max:1000'],
                'package_size' => ['required_if:service_type,livraison', 'nullable', 'string', 'in:small,medium,large'],
                'package_weight' => ['nullable', 'numeric', 'min:0', 'max:50'],
                'is_fragile' => ['nullable'],
                'rider_voice_note' => ['nullable', 'string', 'max:2000'],
                'rider_voice_audio' => ['nullable', 'file', 'max:15360'],
                'promo_code' => ['nullable', 'string'],
                'pricing_mode' => ['nullable', 'string', 'in:fixed,negotiable'],
            ]);

            $pickupLat = (float) $v['pickup_lat'];
            $pickupLng = (float) $v['pickup_lng'];
            $pickupLabel = $v['pickup_label'] ?? null;
            $dropoffLat = isset($v['dropoff_lat']) ? (float) $v['dropoff_lat'] : null;
            $dropoffLng = isset($v['dropoff_lng']) ? (float) $v['dropoff_lng'] : null;
            $dropoffLabel = $v['dropoff_label'] ?? null;
            $distanceM = isset($v['distance_m']) ? (int) $v['distance_m'] : 0;
            $durationS = isset($v['duration_s']) ? (int) $v['duration_s'] : 0;
            $orderMode = $v['order_mode'] ?? 'distance';
            $durationHours = isset($v['duration_hours']) ? (int) $v['duration_hours'] : null;
            $vehicleType = $v['vehicle_type'] ?? 'standard';
            $hasBaggage = filter_var($v['has_baggage'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $luggageCount = (int) ($v['luggage_count'] ?? 0);
            $paymentMethod = $v['payment_method'] ?? 'cash';
            $serviceType = $v['service_type'] ?? 'course';
            $pricingMode = $v['pricing_mode'] ?? 'fixed';
            $recipientName = $v['recipient_name'] ?? null;
            $recipientPhone = $v['recipient_phone'] ?? null;
            $packageDescription = $v['package_description'] ?? null;
            $packageSize = $v['package_size'] ?? null;
            $packageWeight = $v['package_weight'] ?? null;
            $isFragile = filter_var($v['is_fragile'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $passengerName = $v['passenger_name'] ?? null;
            $passengerPhone = $v['passenger_phone'] ?? null;
            $riderVoiceNote = $v['rider_voice_note'] ?? null;
        } else {
            $request->validate([
                'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
                'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
                'pickup.label' => ['nullable', 'string', 'max:255'],
                'dropoff.lat' => ['required_if:service_type,livraison', 'nullable', 'numeric', 'between:-90,90'],
                'dropoff.lng' => ['required_if:service_type,livraison', 'nullable', 'numeric', 'between:-180,180'],
                'dropoff.label' => ['nullable', 'string', 'max:255'],
                'distance_m' => ['nullable', 'numeric', 'min:0', 'max:600000'],
                'duration_s' => ['nullable', 'numeric', 'min:0', 'max:86400'],
                'order_mode' => ['nullable', 'string', 'in:distance,duration'],
                'duration_hours' => ['nullable', 'integer', 'min:1'],
                'price' => ['nullable', 'numeric', 'min:1'],
                'passenger_name' => ['nullable', 'string', 'max:255'],
                'passenger_phone' => ['nullable', 'string', 'max:255'],
                'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
                'has_baggage' => ['nullable', 'boolean'],
                'luggage_count' => ['nullable', 'integer', 'min:0', 'max:5'],
                'payment_method' => ['nullable', 'string', 'in:cash,wallet,card,qr,mobile_money,bonus'],
                'service_type' => ['nullable', 'string', 'in:course,livraison'],
                'recipient_name' => ['required_if:service_type,livraison', 'nullable', 'string', 'min:2', 'max:255'],
                'recipient_phone' => ['required_if:service_type,livraison', 'nullable', 'string', 'max:30', 'regex:/^[0-9+ ]{8,30}$/'],
                'package_description' => ['required_if:service_type,livraison', 'nullable', 'string', 'min:2', 'max:1000'],
                'package_size' => ['required_if:service_type,livraison', 'nullable', 'string', 'in:small,medium,large'],
                'package_weight' => ['nullable', 'numeric', 'min:0', 'max:50'],
                'is_fragile' => ['nullable', 'boolean'],
                'rider_voice_note' => ['nullable', 'string', 'max:2000'],
                'promo_code' => ['nullable', 'string'],
                'pricing_mode' => ['nullable', 'string', 'in:fixed,negotiable'],
            ]);

            $pickupLat = (float) $request->input('pickup.lat');
            $pickupLng = (float) $request->input('pickup.lng');
            $pickupLabel = $request->input('pickup.label');
            $dropoffLat = $request->has('dropoff.lat') ? (float) $request->input('dropoff.lat') : null;
            $dropoffLng = $request->has('dropoff.lng') ? (float) $request->input('dropoff.lng') : null;
            $dropoffLabel = $request->input('dropoff.label');
            $distanceM = (int) $request->input('distance_m', 0);
            $durationS = (int) $request->input('duration_s', 0);
            $orderMode = $request->input('order_mode', 'distance');
            $durationHours = $request->has('duration_hours') ? (int) $request->input('duration_hours') : null;
            $vehicleType = $request->input('vehicle_type', 'standard');
            $hasBaggage = (bool) $request->input('has_baggage', false);
            $luggageCount = (int) $request->input('luggage_count', 0);
            $paymentMethod = $request->input('payment_method', 'cash');
            $serviceType = $request->input('service_type', 'course');
            $pricingMode = $request->input('pricing_mode', 'fixed');
            $recipientName = $request->input('recipient_name');
            $recipientPhone = $request->input('recipient_phone');
            $packageDescription = $request->input('package_description');
            $packageSize = $request->input('package_size');
            $packageWeight = $request->input('package_weight');
            $isFragile = (bool) $request->input('is_fragile', false);
            $passengerName = $request->input('passenger_name');
            $passengerPhone = $request->input('passenger_phone');
            $riderVoiceNote = $request->input('rider_voice_note');
        }

        if ($block = $this->passengerActiveRideConcurrencyResponse($user)) {
            return $block;
        }

        if ($paymentMethod === 'bonus' && ! $this->passengerBonusService->isEligible($user)) {
            return $this->apiError(
                'PASSENGER_BONUS_UNAVAILABLE',
                'Votre bonus de première course a déjà été utilisé.',
                422
            );
        }

        try {
            return DB::transaction(function () use ($user, $isMultipart, $v, $request, $pickupLat, $pickupLng, $pickupLabel, $dropoffLat, $dropoffLng, $dropoffLabel, $distanceM, $durationS, $orderMode, $durationHours, $vehicleType, $hasBaggage, $luggageCount, $paymentMethod, $serviceType, $recipientName, $recipientPhone, $packageDescription, $packageSize, $packageWeight, $isFragile, $passengerName, $passengerPhone, $riderVoiceNote, $pricingMode) {

                // SEC-02 : tarif exclusivement calcule cote serveur (distance client + grilles ; le champ price est ignore).
                if ($orderMode === 'duration' && $durationHours) {
                    $durationPricing = config('duration_pricing', [
                        1 => 5000,
                        2 => 8000,
                        3 => 12000,
                        5 => 20000,
                    ]);
                    $fareAmount = $durationPricing[$durationHours] ?? 50000;
                } else {
                    // Si l'app envoie distance_m=0 (estimation non reçue ou échouée) mais
                    // qu'on dispose des deux points, on recalcule par haversine ×1.3 — même
                    // logique que le fallback de estimateFromCoords quand OSRM est indisponible.
                    if ($distanceM <= 0 && $dropoffLat !== null && $dropoffLng !== null) {
                        $distanceM = (int) round($this->haversineDistanceMeters($pickupLat, $pickupLng, $dropoffLat, $dropoffLng) * 1.3);
                    }

                    // Durée estimée absente : approximation 30 km/h (même règle que estimateFromCoords)
                    if ($durationS <= 0 && $distanceM > 0) {
                        $durationS = (int) round(($distanceM / 1000.0) * (3600 / 30));
                    }

                    $fareAmount = $this->computeFareFromDistance(
                        (float) $distanceM,
                        $vehicleType,
                        (int) $luggageCount,
                        $pickupLat,
                        $pickupLng,
                        $dropoffLat,
                        $dropoffLng,
                        (int) $durationS
                    );
                    if ($serviceType === 'livraison') {
                        $fareAmount += $this->deliveryPricing->breakdown(
                            $packageSize,
                            $packageWeight,
                            $isFragile
                        )['total'];
                    }
                }

                $promoCodeStr = $isMultipart ? ($v['promo_code'] ?? null) : $request->input('promo_code');
                $originalFareAmount = $fareAmount;
                $discountAmount = 0;
                $promoCodeId = null;
                $isFirstRide = $this->passengerBonusService->isEligible($user);

                if ($paymentMethod === 'bonus' && $isFirstRide) {
                    $discountAmount = PassengerBonusService::FIRST_RIDE_BONUS;
                }

                // 1. Calculer la réduction potentielle du code promo
                if ($promoCodeStr) {
                    $promo = \App\Models\PromoCode::where('code', strtoupper(trim((string) $promoCodeStr)))
                        ->lockForUpdate() // Verrouiller pour éviter les dépassements de max_uses
                        ->first();

                    if ($promo && $promo->isValid()) {
                        // Vérifier si l'utilisateur a déjà utilisé ce code
                        $alreadyUsed = $user ? $user->rides()->where('promo_code_id', $promo->id)->exists() : false;
                        if (!$alreadyUsed) {
                            if ($promo->type === 'percentage') {
                                $discountAmount = $originalFareAmount * ($promo->value / 100);
                            } else {
                                $discountAmount = $promo->value;
                            }
                            $promoCodeId = $promo->id;
                        }
                    }
                }

                // 2. Appliquer la réduction automatique de 30% sur la première course (acquisition)
                $firstRideDiscount = 0;
                if ($isFirstRide && $orderMode === 'distance') {
                    $firstRideDiscount = PassengerBonusService::FIRST_RIDE_BONUS;
                }

                // Choisir la remise la plus avantageuse
                if ($firstRideDiscount > $discountAmount) {
                    $discountAmount = $firstRideDiscount;
                    $promoCodeId = null; // On n'utilise pas le code promo s'il est moins avantageux
                }

                // 3. Incrémenter l'usage du code promo SI il est réellement utilisé
                if ($promoCodeId) {
                    $promo = \App\Models\PromoCode::find($promoCodeId);
                    $promo->increment('used_count');
                }

                if ($discountAmount > $originalFareAmount) {
                    $discountAmount = $originalFareAmount;
                }
                $fareAmount = max(0, $originalFareAmount - $discountAmount);

                $deliveryCode = $serviceType === 'livraison' ? (string) random_int(1000, 9999) : null;

                $ride = Ride::create([
                    'rider_id' => $user?->id,
                    'driver_id' => null,
                    'offered_driver_id' => null,
                    'status' => 'requested',
                    'original_fare_amount' => $originalFareAmount,
                    'discount_amount' => $discountAmount,
                    'promo_code_id' => $promoCodeId,
                    'fare_amount' => $fareAmount,
                    'commission_amount' => 0,
                    'driver_earnings_amount' => 0,
                    'currency' => 'XOF',
                    'distance_m' => $distanceM,
                    'duration_s' => $durationS,
                    'order_mode' => $orderMode,
                    'duration_hours' => $durationHours,
                    'pickup_lat' => $pickupLat,
                    'pickup_lng' => $pickupLng,
                    'pickup_address' => $pickupLabel,
                    'dropoff_lat' => $dropoffLat,
                    'dropoff_lng' => $dropoffLng,
                    'dropoff_address' => $dropoffLabel,
                    'passenger_name' => $passengerName,
                    'passenger_phone' => $passengerPhone,
                    'vehicle_type' => $vehicleType,
                    'has_baggage' => $hasBaggage,
                    'luggage_count' => $luggageCount,
                    'payment_method' => $paymentMethod,
                    'service_type' => $serviceType,
                    'recipient_name' => $recipientName,
                    'recipient_phone' => $recipientPhone,
                    'package_description' => $packageDescription,
                    'package_size' => $packageSize,
                    'package_weight' => $packageWeight,
                    'is_fragile' => $isFragile,
                    'delivery_code_hash' => $deliveryCode ? Hash::make($deliveryCode) : null,
                    'delivery_code_encrypted' => $deliveryCode ? Crypt::encryptString($deliveryCode) : null,
                    'rider_voice_note' => $riderVoiceNote,
                    'rider_voice_audio_path' => null,
                    'declined_driver_ids' => [],
                    'pricing_mode' => $pricingMode ?? 'fixed',
                ]);

                if ($isMultipart && $request->hasFile('rider_voice_audio')) {
                    $file = $request->file('rider_voice_audio');
                    if ($file && $file->isValid()) {
                        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'm4a'));
                        if (! preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
                            $ext = 'm4a';
                        }
                        $path = $file->storeAs('ride_voice_notes', $ride->id.'_'.uniqid('', true).'.'.$ext, 'public');
                        $ride->rider_voice_audio_path = $path;
                        $ride->save();
                    }
                }

                $this->rideService->notifyNearbyDrivers($ride);

                return response()->json([
                    'id' => $ride->id,
                    'status' => $ride->status,
                    'rider_id' => $ride->rider_id,
                    'driver_id' => $ride->driver_id,
                    'offered_driver_id' => $ride->offered_driver_id,
                    'distance_m' => $ride->distance_m,
                    'duration_s' => $ride->duration_s,
                    'price' => $ride->fare_amount,
                    'currency' => $ride->currency,
                    'passenger_name' => $ride->passenger_name,
                    'passenger_phone' => $ride->passenger_phone,
                    'rider_voice_note' => $ride->rider_voice_note,
                    'rider_voice_audio_path' => $ride->rider_voice_audio_path,
                    'stop_started_at' => $ride->stop_started_at,
                    'total_stop_duration_s' => $ride->total_stop_duration_s,
                    ...$this->calculateRideFareBreakdown($ride),
                    'vehicle_type' => $ride->vehicle_type,
                    'has_baggage' => (bool) $ride->has_baggage,
                    'service_type' => $ride->service_type,
                    ...$this->deliveryFields($ride),
                    'delivery_code' => $this->deliveryCodeForPassenger($ride),
                    'pricing_mode' => $ride->pricing_mode,
                ], 201);
            });
        } catch (\Exception $e) {

            \Log::error("CRITICAL ERROR during ride creation: " . $e->getMessage(), [
                'user_id' => $user?->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->apiError('RIDE_CREATE_FAILED', 'Erreur serveur lors de la création de la course.', 500);
        }
    }


    public function accept(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();

        if (!$driver || !$driver->isDriver()) {
            return $this->apiForbidden();
        }

        // Vérification de l'abonnement du chauffeur
        $remainingRides = (int) DB::table('driver_profiles')->where('user_id', $driver->id)->value('subscription_remaining_rides');
        if ($remainingRides <= 0) {
            $packPrice = (int) app(\App\Services\EconomicModelService::class)->get()['driver_pack_price'];
            return response()->json([
                'code' => 'subscription_block',
                'message' => "Votre pack est épuisé. Renouvelez-le pour {$packPrice} F afin de recevoir des courses.",
            ], 403);
        }

        $ride = null;

        try {
            DB::transaction(function () use ($id, $driver, &$ride) {
                // RACE-01 : Mise à jour atomique conditionnelle (Atomic Conditional UPDATE).
                // Au lieu de faire findOrFail() puis check() puis save() en 3 étapes séparées
                // (ce qui créait une fenêtre de race condition si deux chauffeurs acceptaient
                // en même temps), on délègue la décision à la base de données en une seule
                // requête SQL atomique. MySQL/InnoDB garantit qu'un seul UPDATE peut réussir
                // sur la même ligne avec les mêmes conditions simultanément.
                $updated = Ride::where('id', $id)
                    ->where('status', 'requested')
                    ->whereNull('driver_id')
                    ->update([
                        'driver_id'          => $driver->id,
                        'offered_driver_id'  => $driver->id,
                        'status'             => 'accepted',
                        'accepted_at'        => now(),
                    ]);

                if (!$updated) {
                    // La course a déjà été acceptée par un autre chauffeur (race condition évitée)
                    // ou elle n'est plus en statut 'requested'. On renvoie une erreur claire.
                    abort($this->apiError(
                        'RIDE_NOT_AVAILABLE',
                        'Cette course n\'est plus disponible. Elle a peut-être déjà été acceptée par un autre chauffeur.',
                        422
                    ));
                }

                // Recharger la course avec les données fraîches après la mise à jour atomique.
                $ride = Ride::with('driver')->findOrFail($id);
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            // Propager les abort() appelés dans la transaction (ex: RIDE_NOT_AVAILABLE)
            throw $e;
        } catch (\Exception $e) {
            \Log::error('TripsController::accept FAILED', [
                'ride_id'   => $id,
                'driver_id' => $driver?->id,
                'error'     => $e->getMessage(),
            ]);
            return $this->apiError('RIDE_ACCEPT_FAILED', 'Erreur serveur lors de l\'acceptation de la course.', 500);
        }

        // Distance d'approche (chauffeur → prise en charge) : enrichissement best-effort
        // HORS transaction — ne doit JAMAIS faire échouer l'acceptation (métrique secondaire).
        try {
            $approach = Ride::estimateApproachDistanceM(
                $driver->last_lat,
                $driver->last_lng,
                $ride->pickup_lat,
                $ride->pickup_lng
            );
            if ($approach !== null) {
                $ride->approach_distance_m = $approach;
                $ride->save();
            }
        } catch (\Throwable $e) {
            \Log::warning('approach_distance_m non enregistrée (non bloquant)', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);
        }

        rescue(fn () => broadcast(new RideAccepted($ride)));

        // Retirer instantanément l'offre de TOUS les autres chauffeurs : le perdant
        // d'une course-course voit « course perdue » plutôt qu'une erreur.
        rescue(fn () => broadcast(new RideTaken((int) $ride->id, (int) $driver->id)));

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                // Course négociable : le passager doit d'abord confirmer le chauffeur
                // (négociation verbale). Course fixe : le chauffeur est déjà en route.
                $isNegotiable = ($ride->pricing_mode ?? 'fixed') === 'negotiable';
                $isDelivery = $ride->service_type === 'livraison';
                $fcm->sendToUser(
                    $passenger,
                    $isNegotiable
                        ? ($isDelivery ? 'Un zem propose de livrer votre colis' : 'Un zem a pris votre course')
                        : ($isDelivery ? 'Livraison acceptée !' : 'Course acceptée !'),
                    $isNegotiable
                        ? $driver->name . " souhaite vous prendre. Confirmez après accord sur le prix."
                        : "Votre zem " . $driver->name . ($isDelivery ? ' part récupérer le colis.' : ' est en route.'),
                    [
                        'ride_id' => (string) $ride->id,
                        'type' => $isNegotiable ? 'ride_negotiation_pending' : 'ride_accepted',
                    ]
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Accepted Notification Error: " . $e->getMessage());
        }

        // Course fixe : toujours confirmée. Négociable : true seulement si le
        // passager a déjà confirmé (même sémantique que driverRideShow).
        $isNegotiable = ($ride->pricing_mode ?? 'fixed') === 'negotiable';
        return response()->json([
            'ok' => true,
            'ride_id' => $ride->id,
            'status' => $ride->status,
            'pricing_mode' => $ride->pricing_mode ?? 'fixed',
            'negotiation_confirmed' => !$isNegotiable || $ride->negotiation_confirmed_at !== null,
        ]);
    }


    public function decline(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::findOrFail($id);
        if ($ride->status !== 'requested') {
            return response()->json(['message' => 'Ride not available'], 422);
        }
        if ($ride->offered_driver_id !== $driver->id) {
            return response()->json(['message' => 'Ride not offered to this driver'], 422);
        }

        $declined = $ride->declined_driver_ids ?? [];
        if (!in_array($driver->id, $declined, true)) {
            $declined[] = $driver->id;
        }

        if ($ride->driver_id === $driver->id) {
            $ride->driver_id = null;
        }

        $ride->declined_driver_ids = $declined;
        $ride->offered_driver_id = null;
        $ride->save();

        rescue(fn () => broadcast(new RideDeclined($ride->fresh(['rider']), $driver)));

        $nextDriver = $this->offerRideToNextDriver($ride);

        return response()->json([
            'ok' => true,
            'reoffered' => $nextDriver !== null,
            'next_driver_id' => $nextDriver?->id,
        ]);
    }

    /**
     * POST /driver/trips/{id}/propose-fare
     *
     * Négociation verbale : le chauffeur propose le prix convenu au téléphone.
     * Enregistre `negotiated_fare` (proposé, pas encore verrouillé) et le pousse
     * en direct sur l'écran de confirmation du passager. Réutilisable tant que le
     * passager n'a pas confirmé.
     */
    public function proposeFare(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return $this->apiForbidden();
        }

        $data = $request->validate([
            'fare' => ['required', 'integer', 'min:100', 'max:1000000'],
        ]);

        $ride = Ride::where('id', $id)->where('driver_id', $driver->id)->first();
        if (!$ride) {
            return $this->apiError('RIDE_NOT_FOUND', 'Course introuvable.', 404);
        }
        if (($ride->pricing_mode ?? 'fixed') !== 'negotiable') {
            return $this->apiError('NOT_NEGOTIABLE', 'Cette course n\'est pas négociable.', 422);
        }
        if ($ride->negotiation_confirmed_at !== null) {
            return $this->apiError('ALREADY_CONFIRMED', 'Le prix a déjà été confirmé.', 422);
        }
        if ($ride->status !== 'accepted') {
            return $this->apiError('INVALID_STATE', 'Course non modifiable.', 422);
        }

        $ride->negotiated_fare = (int) $data['fare'];
        $ride->save();

        rescue(fn () => broadcast(new RideFareProposed($ride)));

        return response()->json([
            'ok' => true,
            'ride_id' => $ride->id,
            'proposed_fare' => (int) $ride->negotiated_fare,
        ]);
    }

    /**
     * POST /driver/trips/{id}/enroute
     *
     * Le chauffeur part chercher le client. Notifie le passager (bouton
     * « Suivre mon chauffeur sur la carte »). N'altère pas le statut : le suivi
     * s'appuie sur les positions GPS déjà diffusées.
     */
    public function enroute(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return $this->apiForbidden();
        }

        $ride = Ride::where('id', $id)->where('driver_id', $driver->id)->first();
        if (!$ride) {
            return $this->apiError('RIDE_NOT_FOUND', 'Course introuvable.', 404);
        }

        rescue(fn () => broadcast(new RideEnroute((int) $ride->id, (int) $ride->rider_id)));

        return response()->json(['ok' => true, 'ride_id' => $ride->id]);
    }

    /**
     * POST /passenger/rides/{id}/confirm-negotiation
     *
     * Négociation verbale : le passager confirme le chauffeur qui a pris sa
     * course négociable (après accord sur le prix au téléphone). Active
     * « Aller chercher mon client » côté chauffeur.
     */
    public function confirmNegotiation(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();

        $ride = Ride::where('id', $id)->where('rider_id', $user?->id)->first();
        if (!$ride) {
            return $this->apiError('RIDE_NOT_FOUND', 'Course introuvable.', 404);
        }
        if (($ride->pricing_mode ?? 'fixed') !== 'negotiable') {
            return $this->apiError('NOT_NEGOTIABLE', 'Cette course n\'est pas négociable.', 422);
        }
        if ($ride->status !== 'accepted' || !$ride->driver_id) {
            return $this->apiError('NO_DRIVER_TO_CONFIRM', 'Aucun chauffeur en attente de confirmation.', 422);
        }

        // Prix optionnel convenu verbalement ; sinon on garde l'estimation.
        $agreedFare = $request->input('agreed_fare');
        if (is_numeric($agreedFare) && (int) $agreedFare > 0) {
            $ride->negotiated_fare = (int) $agreedFare;
        }
        $ride->negotiation_confirmed_at = now();
        $ride->save();

        rescue(fn () => broadcast(new RideNegotiationConfirmed($ride, (int) $ride->driver_id, true)));

        return response()->json([
            'ok' => true,
            'ride_id' => $ride->id,
            'negotiation_confirmed' => true,
            'fare' => (int) ($ride->negotiated_fare ?? $ride->fare_amount ?? 0),
        ]);
    }

    /**
     * POST /passenger/rides/{id}/reject-negotiation
     *
     * Le passager refuse le chauffeur (pas d'accord sur le prix). La course
     * retourne dans le pool (statut 'requested'), le chauffeur est écarté et
     * notifié, les autres chauffeurs la revoient.
     */
    public function rejectNegotiation(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();

        $ride = Ride::where('id', $id)->where('rider_id', $user?->id)->first();
        if (!$ride) {
            return $this->apiError('RIDE_NOT_FOUND', 'Course introuvable.', 404);
        }
        if (($ride->pricing_mode ?? 'fixed') !== 'negotiable') {
            return $this->apiError('NOT_NEGOTIABLE', 'Cette course n\'est pas négociable.', 422);
        }
        if ($ride->status !== 'accepted' || !$ride->driver_id) {
            return $this->apiError('NO_DRIVER_TO_REJECT', 'Aucun chauffeur à refuser.', 422);
        }

        $rejectedDriverId = (int) $ride->driver_id;

        $declined = $ride->declined_driver_ids ?? [];
        if (!in_array($rejectedDriverId, $declined, true)) {
            $declined[] = $rejectedDriverId;
        }

        $ride->declined_driver_ids = $declined;
        $ride->driver_id = null;
        $ride->offered_driver_id = null;
        $ride->accepted_at = null;
        $ride->negotiation_confirmed_at = null;
        $ride->status = 'requested';
        $ride->save();

        // Prévenir le chauffeur écarté puis remettre la course dans le pool.
        rescue(fn () => broadcast(new RideNegotiationConfirmed($ride, $rejectedDriverId, false)));
        rescue(fn () => broadcast(new RideRequested($ride)));

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => 'requested']);
    }

    public function arrived(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);

        if ($ride->driver_id !== ($driver?->id) || !in_array($ride->status, ['accepted', 'pickup'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        $ride->status = 'arrived';
        $ride->arrived_at = now();
        $ride->save();

        try {
            rescue(fn () => broadcast(new RideArrived($ride->id, $ride->rider_id, $ride->arrived_at->toIso8601String())));
            \Log::info("[Arrived] Broadcast sent for ride {$ride->id} to rider {$ride->rider_id}");
        } catch (\Exception $e) {
            \Log::error("[Arrived] Broadcast FAILED for ride {$ride->id}: " . $e->getMessage());
        }

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $passenger,
                    "Votre chauffeur est arrivé !",
                    "Le chauffeur est au point de prise en charge.",
                    ['ride_id' => (string) $ride->id, 'type' => 'driver_arrived']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Passenger Notification Error: " . $e->getMessage());
        }

        return response()->json(['ok' => true, 'arrived_at' => $ride->arrived_at]);
    }

    public function start(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->driver_id !== ($driver?->id) || !in_array($ride->status, ['accepted', 'arrived', 'pickup'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }
        $ride->status = 'ongoing';
        $ride->started_at = now();
        $ride->save();

        rescue(fn () => broadcast(new RideStarted($ride)));

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $passenger,
                    $ride->service_type === 'livraison' ? 'Colis récupéré !' : "C'est parti !",
                    $ride->service_type === 'livraison' ? 'Votre colis est maintenant en route vers le destinataire.' : 'Votre course a commencé. Bon voyage !',
                    ['ride_id' => (string) $ride->id, 'type' => 'ride_started']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Started Notification Error: " . $e->getMessage());
        }

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }

    public function complete(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->driver_id !== ($driver?->id)) {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        // Idempotence réseau : si le premier appel a abouti mais que sa réponse s'est
        // perdue, le retry du zem doit rouvrir le reçu au lieu d'afficher Invalid state.
        if ($ride->status === 'completed') {
            return response()->json([
                'ok' => true,
                'ride_id' => $ride->id,
                'status' => $ride->status,
                'earned' => (int) ($ride->driver_earnings_amount ?? 0),
                'payment_link' => $ride->payment_link,
                'already_completed' => true,
            ]);
        }

        // Compatibilité avec d'anciennes courses qui utilisaient `started` pour
        // représenter exactement le même état métier que `ongoing`.
        if ($ride->status === 'started') {
            $ride->status = 'ongoing';
            $ride->started_at ??= now();
            $ride->save();
        }

        if ($ride->status !== 'ongoing') {
            return response()->json([
                'message' => 'Invalid state',
                'status' => $ride->status,
            ], 422);
        }

        if ($ride->service_type === 'livraison') {
            $data = $request->validate([
                'delivery_code' => ['required', 'digits:4'],
            ]);
            if (! $ride->delivery_code_hash || ! Hash::check((string) $data['delivery_code'], $ride->delivery_code_hash)) {
                return $this->apiError('INVALID_DELIVERY_CODE', 'Le code de confirmation est incorrect.', 422);
            }
        }

        try {
            // Logique de complétion centralisée (partagée avec l'admin) — voir RideCompletionService
            $result = app(\App\Services\RideCompletionService::class)->complete(
                $ride,
                $request->has('distance_m') ? (int) $request->input('distance_m') : null
            );

            if ($result['ride']->service_type === 'livraison') {
                $result['ride']->delivery_confirmed_at = now();
                $result['ride']->save();
            }

            return response()->json([
                'ok' => true,
                'ride_id' => $result['ride']->id,
                'status' => $result['ride']->status,
                'earned' => $result['driverAmount'],
                'payment_link' => $result['ride']->payment_link ?? null
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("RideComplete FAILED", [
                'ride_id' => $id,
                'driver_id' => $driver?->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to complete ride',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancelByDriver(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::where('id', $id)
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        if (!in_array($ride->status, ['accepted', 'ongoing', 'requested'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);
        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = $data['reason'] ?? null;
        $ride->save();

        rescue(fn () => broadcast(new RideCancelled($ride->fresh(['driver', 'rider']), 'driver', $driver)));

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $passenger,
                    $ride->service_type === 'livraison' ? 'Livraison annulée' : 'Course annulée',
                    $ride->service_type === 'livraison' ? 'Le zem a annulé la livraison.' : 'Le zem a annulé la course.',
                    ['ride_id' => (string) $ride->id, 'type' => 'ride_cancelled']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Cancelled By Driver Error: " . $e->getMessage());
        }

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }


    public function cancelByPassenger(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->rider_id !== ($user?->id) || !in_array($ride->status, ['requested', 'accepted'])) {
            return $this->apiInvalidState();
        }
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);
        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = $data['reason'] ?? null;
        $ride->save();

        rescue(fn () => broadcast(new RideCancelled($ride, 'passenger', $user)));

        // Notify driver
        try {
            $driver = $ride->driver;
            if ($driver) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $driver,
                    $ride->service_type === 'livraison' ? 'Livraison annulée' : 'Course annulée',
                    $ride->service_type === 'livraison' ? "L'expéditeur a annulé la livraison." : 'Le passager a annulé la course.',
                    ['ride_id' => (string) $ride->id, 'type' => 'ride_cancelled']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Cancelled By Passenger Error: " . $e->getMessage());
        }

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }

    public function sos(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->rider_id !== ($user?->id)) {
            return $this->apiForbidden();
        }

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        \Log::warning("SOS Alert triggered by passenger {$user->id} for ride {$ride->id}. Coordinates: " . ($data['latitude'] ?? 'unknown') . ', ' . ($data['longitude'] ?? 'unknown'));

        // Simuler la transmission en broadcast
        // broadcast(new App\Events\SosAlertTriggered($ride, $user, $data['latitude'] ?? null, $data['longitude'] ?? null));

        return response()->json([
            'ok' => true,
            'message' => 'Alerte SOS transmise avec succès au centre de contrôle Kêkênon.',
        ]);
    }

    public function updateDriverStatus(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'online' => ['required', 'boolean'],
        ]);

        $driver->is_online = (bool) $data['online'];
        $driver->save();

        return response()->json([
            'ok' => true,
            'user_id' => $driver->id,
            'is_online' => $driver->is_online,
        ]);
    }

    public function driverRides(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $query = Ride::query()->where('driver_id', $driver->id);
        if ($status) {
            $query->where('status', $status);
        }

        $rides = $query->orderByDesc('id')->paginate($perPage);
        return response()->json($rides);
    }

    public function driverRideShow(int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::with('rating')->findOrFail($id);
        if ($ride->driver_id !== $driver->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }

        if ($ride) {
            $breakdown = $this->calculateRideFareBreakdown($ride);
            $ride->fare_amount = $breakdown['total_fare'];
            $data = array_merge($ride->toArray(), $breakdown);
            // Map rating.stars to rating for frontend
            $data['rating'] = $ride->rating ? $ride->rating->stars : null;
            // Négociation verbale : true si course fixe OU passager a déjà confirmé.
            // C'est ce booléen qui active « Aller chercher mon client » côté chauffeur.
            $isNegotiable = ($ride->pricing_mode ?? 'fixed') === 'negotiable';
            $data['negotiation_confirmed'] = !$isNegotiable || $ride->negotiation_confirmed_at !== null;
            return response()->json($data);
        }
        return response()->json($ride);
    }

    public function currentPassengerRide(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::where('rider_id', $user->id)
            ->where(function ($q) {
                $q->whereIn('status', ['requested', 'accepted', 'arrived', 'pickup', 'started', 'ongoing'])
                    ->orWhere(function ($sq) {
                        $sq->where('status', 'completed')
                            ->where('completed_at', '>=', now()->subMinutes(15))
                            ->where(function ($sq2) {
                                $sq2->where('payment_status', '!=', 'completed')
                                    ->whereDoesntHave('rating');
                            });
                    });
            })
            ->with(['driver.driverProfile', 'rating'])
            ->orderByDesc('id')
            ->first();

        if ($ride) {
            $breakdown = $this->calculateRideFareBreakdown($ride);
            $ride->fare_amount = $breakdown['total_fare'];
            $data = array_merge($ride->toArray(), $breakdown);
            $data['delivery_code'] = $this->deliveryCodeForPassenger($ride);
            return response()->json($data);
        }
        return response()->json($ride);
    }

    public function activeRidesCount(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['count' => 0]);
        }

        $statuses = ['requested', 'accepted', 'arrived', 'pickup', 'started', 'ongoing'];

        $count = Ride::where('rider_id', $user->id)->whereIn('status', $statuses)->count();

        $hasRequested = Ride::where('rider_id', $user->id)->where('status', 'requested')->exists();

        return response()->json([
            'count' => $count,
            'has_requested' => $hasRequested,
        ]);
    }

    public function passengerRides(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $query = Ride::query()->where('rider_id', $user->id);
        if ($status) {
            $query->where('status', $status);
        }

        $rides = $query->orderByDesc('id')->paginate($perPage);
        return response()->json($rides);
    }

    public function passengerRideShow(int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $ride = Ride::with('driver.driverProfile')->findOrFail($id);
        if ($ride->rider_id !== $user->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }

        return response()->json([
            'id' => $ride->id,
            'status' => $ride->status,
            'created_at' => $ride->created_at?->toIso8601String(),
            'arrived_at' => $ride->arrived_at ? $ride->arrived_at->toIso8601String() : null,
            'rider_id' => $ride->rider_id,
            'driver_id' => $ride->driver_id,
            'fare_amount' => (int) ($ride->fare_amount ?? 0),
            'currency' => $ride->currency ?? 'XOF',
            'distance_m' => (int) ($ride->distance_m ?? 0),
            'duration_s' => (int) ($ride->duration_s ?? 0),
            'pickup' => [
                'address' => $ride->pickup_address ?? null,
                'lat' => $ride->pickup_lat ?? null,
                'lng' => $ride->pickup_lng ?? null,
            ],
            'dropoff' => [
                'address' => $ride->dropoff_address ?? null,
                'lat' => $ride->dropoff_lat ?? null,
                'lng' => $ride->dropoff_lng ?? null,
            ],
            'driver' => $ride->driver ? [
                'id' => $ride->driver->id,
                'name' => $ride->driver->name,
                'phone' => $ride->driver->phone,
                'photo' => $ride->driver->photo,
                'vehicle' => $ride->driver->driverProfile ? [
                    'make' => $ride->driver->driverProfile->vehicle_make,
                    'model' => $ride->driver->driverProfile->vehicle_model,
                    'year' => $ride->driver->driverProfile->vehicle_year,
                    'color' => $ride->driver->driverProfile->vehicle_color,
                    'license_plate' => $ride->driver->driverProfile->license_plate,
                    'type' => $ride->driver->driverProfile->vehicle_type,
                ] : null,
                'rating_average' => $ride->driver ? (float) \DB::table('ratings')
                    ->where('driver_id', $ride->driver->id)
                    ->avg('stars') : 0.0,
                'lat' => $ride->driver->last_lat,
                'lng' => $ride->driver->last_lng,
            ] : null,
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'rider_voice_note' => $ride->rider_voice_note,
            'rider_voice_audio_path' => $ride->rider_voice_audio_path,
            'stop_started_at' => $ride->stop_started_at,
            'total_stop_duration_s' => $ride->total_stop_duration_s,
            'payment_method' => $ride->payment_method,
            'payment_status' => $ride->payment_status,
            'payment_link' => $ride->payment_link,
            'service_type' => $ride->service_type,
            ...$this->deliveryFields($ride),
            'delivery_code' => $this->deliveryCodeForPassenger($ride),
            // Négociation : nécessaire pour router le passager vers l'écran
            // Confirmer/Refuser (verbal) au lieu du suivi direct.
            'pricing_mode' => $ride->pricing_mode ?? 'fixed',
            'negotiated_fare' => $ride->negotiated_fare,
            'negotiation_confirmed_at' => $ride->negotiation_confirmed_at,
            ...$this->calculateRideFareBreakdown($ride),
        ]);
    }

    public function driverStats(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $from = $request->query('from');
        $to = $request->query('to');

        // Completed rides and earnings over the selected range
        $completedQuery = Ride::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'completed');

        if ($from) {
            $completedQuery->whereDate('completed_at', '>=', $from);
        }
        if ($to) {
            $completedQuery->whereDate('completed_at', '<=', $to);
        }

        $totalRides = (clone $completedQuery)->count();
        $totalEarnings = (clone $completedQuery)->sum('driver_earnings_amount');
        $totalFare = (clone $completedQuery)->sum('fare_amount');
        $lastRide = (clone $completedQuery)->orderByDesc('completed_at')->first();

        // Acceptance / cancellation rates: consider all rides assigned to this driver in the range
        $assignedQuery = Ride::query()->where('driver_id', $driver->id);
        if ($from) {
            $assignedQuery->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $assignedQuery->whereDate('created_at', '<=', $to);
        }

        $totalAssigned = (clone $assignedQuery)->count();
        $acceptedCount = (clone $assignedQuery)
            ->whereIn('status', ['accepted', 'ongoing', 'completed'])
            ->count();
        $cancelledCount = (clone $assignedQuery)
            ->where('status', 'cancelled')
            ->count();

        $acceptanceRate = $totalAssigned > 0
            ? round(($acceptedCount * 100.0) / $totalAssigned, 1)
            : 0.0;
        $cancellationRate = $totalAssigned > 0
            ? round(($cancelledCount * 100.0) / $totalAssigned, 1)
            : 0.0;

        // Rating: lifetime average and count of ratings for this driver
        $ratingRow = DB::table('ratings')
            ->where('driver_id', $driver->id)
            ->selectRaw('COALESCE(AVG(stars),0) as avg_stars, COUNT(*) as cnt')
            ->first();

        $ratingAverage = $ratingRow && $ratingRow->cnt > 0
            ? round((float) $ratingRow->avg_stars, 2)
            : null;
        $ratingCount = $ratingRow ? (int) $ratingRow->cnt : 0;

        return response()->json([
            'driver_id' => $driver->id,
            'total_rides' => $totalRides,
            'total_earnings' => (int) $totalEarnings,
            'total_fare' => (int) $totalFare,
            'last_completed_at' => $lastRide ? $lastRide->completed_at->toIso8601String() : null,
            'currency' => 'XOF',
            'range' => [
                'from' => $from,
                'to' => $to,
            ],
            'rating_average' => $ratingAverage,
            'rating_count' => $ratingCount,
            'acceptance_rate' => $acceptanceRate,
            'cancellation_rate' => $cancellationRate,
            'online_hours' => null,
        ]);
    }

    public function driverCurrentRide()
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $driver->refresh();

        $lat = $driver->last_lat;
        $lng = $driver->last_lng;
        $hasDriverPosition = $lat !== null && $lng !== null;

        $earthRadiusKm = 6371.0;
        $searchRadiusKm = (float) config('app.search_radius_km', 10.0);

        $distanceFormula = $hasDriverPosition ? "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$lat} - rides.pickup_lat) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS(rides.pickup_lat)) *
                    POWER(SIN(RADIANS({$lng} - rides.pickup_lng) / 2), 2)
                )
            )
        )" : '';

        $rideQuery = Ride::query()
            ->where(function ($query) use ($driver) {
                $query->where('driver_id', $driver->id)
                    ->whereIn('status', ['accepted', 'arrived', 'pickup', 'ongoing']);
            })
            ->orWhere(function ($query) use ($driver) {
                $query->where('offered_driver_id', $driver->id)
                    ->where('status', 'requested');
            });

        if ($hasDriverPosition) {
            $rideQuery->orWhere(function ($query) use ($driver, $distanceFormula, $searchRadiusKm) {
                if (! $driver->is_online) {
                    $query->whereRaw('0 = 1');

                    return;
                }
                $query->where('status', 'requested')
                    ->whereNull('offered_driver_id')
                    ->whereNotNull('pickup_lat')
                    ->whereNotNull('pickup_lng')
                    ->whereRaw("{$distanceFormula} <= ?", [$searchRadiusKm]);
                $this->whereDriverHasNotDeclinedRide($query, $driver);
            });
        } else {
            // Pas de GPS enregistré sur users : ne pas injecter une formule SQL invalide ;
            // proposer quand même les demandes « broadcast » (sans filtre distance).
            $rideQuery->orWhere(function ($query) use ($driver) {
                if (! $driver->is_online) {
                    $query->whereRaw('0 = 1');

                    return;
                }
                $query->where('status', 'requested')
                    ->whereNull('offered_driver_id')
                    ->whereNotNull('pickup_lat')
                    ->whereNotNull('pickup_lng');
                $this->whereDriverHasNotDeclinedRide($query, $driver);
            });
        }

        $ride = $rideQuery->orderByDesc('id')->first();

        if (!$ride) {
            return response()->json(null, 204);
        }

        $passenger = $ride->rider_id ? User::find($ride->rider_id) : null;

        return response()->json([
            'id' => $ride->id,
            'pickup_address' => $ride->pickup_address,
            'dropoff_address' => $ride->dropoff_address,
            'fare_amount' => (int) ($ride->fare_amount ?? 0),
            'status' => $ride->status,
            'pickup_lat' => $ride->pickup_lat,
            'pickup_lng' => $ride->pickup_lng,
            'dropoff_lat' => $ride->dropoff_lat,
            'dropoff_lng' => $ride->dropoff_lng,
            'duration_s' => (int) ($ride->duration_s ?? 0),
            'distance_m' => (int) ($ride->distance_m ?? 0),
            'service_type' => $ride->service_type,
            ...$this->deliveryFields($ride),
            'accepted_at' => $ride->accepted_at,
            'started_at' => $ride->started_at,
            'completed_at' => $ride->completed_at,
            'rider' => $this->formatPassenger($passenger),
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'stop_started_at' => $ride->stop_started_at,
            'total_stop_duration_s' => $ride->total_stop_duration_s,
            'payment_method' => $ride->payment_method,
            // Négociation : indispensables au gating de « Aller chercher mon client ».
            'pricing_mode' => $ride->pricing_mode ?? 'fixed',
            'negotiated_fare' => $ride->negotiated_fare,
            'negotiation_confirmed_at' => $ride->negotiation_confirmed_at,
            'negotiation_confirmed' => ($ride->pricing_mode ?? 'fixed') !== 'negotiable'
                || $ride->negotiation_confirmed_at !== null,
            ...$this->calculateRideFareBreakdown($ride),
        ]);
    }

    public function driverNextOffer(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $driver->refresh();

        // Nouveau système : Diffusion au lieu de ciblage individuel
        // On cherche une course 'requested' dans un rayon de 10km du chauffeur
        // OU une course sans coordonnées (créée manuellement par l'admin)
        $lat = $driver->last_lat;
        $lng = $driver->last_lng;

        if ($lat === null || $lng === null) {
            // Sans last_lat/last_lng (GPS pas encore persisté, simulateur, etc.) :
            // l’ancien code ne listait que les courses sans pickup_lat/lng — invisible pour une vraie demande passager.
            // On renvoie les dernières courses « requested » (max 5), comme le dashboard admin les voit.
            $rides = Ride::query()
                ->where('status', 'requested');
            $this->whereDriverHasNotDeclinedRide($rides, $driver);

            $rides = $rides->with('rider')->orderByDesc('id')->limit(5)->get();

            if ($rides->isEmpty()) {
                return response()->json([], 200);
            }

            $formattedRides = $rides->map(function ($ride) {
                $passenger = $ride->rider;
                return [
                    'id' => $ride->id,
                    'pickup_address' => $ride->pickup_address ?? null,
                    'dropoff_address' => $ride->dropoff_address ?? null,
                    'fare_amount' => (int) ($ride->fare_amount ?? 0),
                    'status' => $ride->status,
                    'pickup_lat' => $ride->pickup_lat,
                    'pickup_lng' => $ride->pickup_lng,
                    'dropoff_lat' => $ride->dropoff_lat,
                    'dropoff_lng' => $ride->dropoff_lng,
                    'rider' => $this->formatPassenger($passenger),
                    'passenger_name' => $ride->passenger_name,
                    'passenger_phone' => $ride->passenger_phone,
                    'rider_voice_note' => $ride->rider_voice_note,
                    'rider_voice_audio_path' => $ride->rider_voice_audio_path,
                    'vehicle_type' => $ride->vehicle_type,
                    'has_baggage' => (bool) $ride->has_baggage,
                    'service_type' => $ride->service_type,
                    ...$this->deliveryFields($ride),
                    'payment_method' => $ride->payment_method,
                    'pricing_mode' => $ride->pricing_mode ?? 'fixed',
                    'negotiated_fare' => $ride->negotiated_fare,
                ];
            });

            return response()->json($formattedRides);
        }

        $earthRadiusKm = 6371.0;
        $searchRadiusKm = (float) config('app.search_radius_km', 10.0);

        $distanceFormula = "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$lat} - rides.pickup_lat) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS(rides.pickup_lat)) *
                    POWER(SIN(RADIANS({$lng} - rides.pickup_lng) / 2), 2)
                )
            )
        )";

        $rides = Ride::query()
            ->where('status', 'requested')
            ->where(function ($q) use ($distanceFormula, $searchRadiusKm) {
                // Rides with coordinates within radius
                $q->where(function ($sub) use ($distanceFormula, $searchRadiusKm) {
                    $sub->whereNotNull('pickup_lat')
                        ->whereNotNull('pickup_lng')
                        ->whereRaw("{$distanceFormula} <= ?", [$searchRadiusKm]);
                })
                    // OR rides without coordinates (manual admin rides)
                    ->orWhere(function ($sub) {
                    $sub->whereNull('pickup_lat')->orWhereNull('pickup_lng');
                });
            });

        $this->whereDriverHasNotDeclinedRide($rides, $driver);

        $rides = $rides->with('rider')->orderByDesc('id')->limit(5)->get();

        if ($rides->isEmpty()) {
            return response()->json([], 200);
        }

        $formattedRides = $rides->map(function ($ride) {
            $passenger = $ride->rider;
            return [
                'id' => $ride->id,
                'pickup_address' => $ride->pickup_address ?? null,
                'dropoff_address' => $ride->dropoff_address ?? null,
                'fare_amount' => (int) ($ride->fare_amount ?? 0),
                'status' => $ride->status,
                'pickup_lat' => $ride->pickup_lat,
                'pickup_lng' => $ride->pickup_lng,
                'dropoff_lat' => $ride->dropoff_lat,
                'dropoff_lng' => $ride->dropoff_lng,
                'rider' => $this->formatPassenger($passenger),
                'passenger_name' => $ride->passenger_name,
                'passenger_phone' => $ride->passenger_phone,
                'rider_voice_note' => $ride->rider_voice_note,
                'rider_voice_audio_path' => $ride->rider_voice_audio_path,
                'vehicle_type' => $ride->vehicle_type,
                'has_baggage' => (bool) $ride->has_baggage,
                'service_type' => $ride->service_type,
                ...$this->deliveryFields($ride),
                'payment_method' => $ride->payment_method,
                'pricing_mode' => $ride->pricing_mode ?? 'fixed',
                'negotiated_fare' => $ride->negotiated_fare,
            ];
        });

        return response()->json($formattedRides);
    }

    public function updateDriverLocation(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        // GPS-01 : Stratégie Redis-first pour éviter le bottleneck sur la table `users`.
        //
        // PROBLÈME RÉSOLU : Sans Redis, l'application exécutait un UPDATE sur la table `users`
        // toutes les 5 secondes par chauffeur. Avec 500 chauffeurs, cela génère 100 requêtes
        // d'écriture par seconde sur la table la plus sollicitée du système (authentification,
        // jointures partout), causant des verrous de lignes et un ralentissement global.
        //
        // SOLUTION : On stocke la position en Redis (mémoire, <1ms) immédiatement.
        // On ne flushera en SQL que toutes les 60 secondes via un flag Redis dédié.
        // Si une course est active, on persiste aussi en SQL pour la cohérence des requêtes
        // de proximité (findNearbyDrivers) qui lisent encore `users.last_lat`.
        $redisAvailable = false;
        try {
            $locationPayload = [
                'lat'        => $lat,
                'lng'        => $lng,
                'updated_at' => now()->toIso8601String(),
            ];

            // Clé principale : position temps réel du chauffeur (expire après 10 minutes d'inactivité)
            \Cache::store('redis')->put(
                "driver:location:{$driver->id}",
                $locationPayload,
                600 // 10 minutes
            );

            $redisAvailable = true;
        } catch (\Exception $redisEx) {
            // Redis inaccessible : on log et on continue avec la persistance SQL classique.
            \Log::warning("GPS Redis unavailable for driver {$driver->id}, falling back to SQL", [
                'error' => $redisEx->getMessage(),
            ]);
        }

        // Persistance SQL : uniquement si Redis est indisponible (fallback),
        // ou toutes les 60 secondes (réduction de ~92% des écritures), ou si une course est active.
        $shouldPersistToSQL = !$redisAvailable;

        if ($redisAvailable && !$shouldPersistToSQL) {
            // On persiste en SQL seulement si le flag "dernière persistance" a expiré (> 60s)
            $sqlFlushKey = "driver:location_sql_flush:{$driver->id}";
            $alreadyFlushed = \Cache::store('redis')->has($sqlFlushKey);

            if (!$alreadyFlushed) {
                $shouldPersistToSQL = true;
                // Poser le flag pour 60 secondes — la prochaine persistance SQL sera dans 60s
                \Cache::store('redis')->put($sqlFlushKey, 1, 60);
            }
        }

        if ($shouldPersistToSQL) {
            // Mise à jour SQL directe sur les colonnes GPS uniquement (pas de touch/updated_at global)
            // pour minimiser l'impact sur les index et éviter d'invalider le cache de session.
            \DB::table('users')->where('id', $driver->id)->update([
                'last_lat'         => $lat,
                'last_lng'         => $lng,
                'last_location_at' => now(),
            ]);
            // Synchroniser le modèle en mémoire pour la réponse et le broadcast
            $driver->last_lat = $lat;
            $driver->last_lng = $lng;
            $driver->last_location_at = now();
        } else {
            // Redis seul : synchroniser le modèle en mémoire sans écriture SQL
            $driver->last_lat = $lat;
            $driver->last_lng = $lng;
            $driver->last_location_at = now();
        }

        // Vérifier si une course active est en cours pour persister en SQL immédiatement.
        // Les requêtes de proximité (findNearbyDrivers) lisent `users.last_lat` en SQL.
        $rideId = $request->input('ride_id');
        $ride = null;
        if ($rideId) {
            $ride = Ride::find($rideId);
        }
        if (!$ride) {
            $ride = Ride::query()
                ->where('driver_id', $driver->id)
                ->whereIn('status', ['accepted', 'ongoing'])
                ->orderByDesc('id')
                ->first();
        }

        // Si une course est active et qu'on n'a pas encore fait la persistance SQL, on la fait.
        if ($ride && !$shouldPersistToSQL) {
            \DB::table('users')->where('id', $driver->id)->update([
                'last_lat'         => $lat,
                'last_lng'         => $lng,
                'last_location_at' => now(),
            ]);
        }

        // Odomètre serveur (TARIFICATION) : accumuler la distance RÉELLEMENT parcourue pendant
        // la course (statut 'ongoing'). Les pings foreground ET background arrivent ici → source
        // de vérité pour facturer le mouvement réel du chauffeur, pas l'estimation vers la
        // destination saisie. La trace est lue à la complétion (RideCompletionService).
        if ($ride && $ride->status === 'ongoing') {
            try {
                $trackKey = "ride:track:{$ride->id}";
                $track = \Cache::store('redis')->get($trackKey);
                if (is_array($track) && isset($track['last_lat'], $track['last_lng'])) {
                    $seg = $this->haversineDistanceMeters((float) $track['last_lat'], (float) $track['last_lng'], $lat, $lng);
                    $dist = (int) ($track['dist_m'] ?? 0);
                    // Filtres : ignorer le bruit GPS (<8 m, véhicule à l'arrêt) et les sauts
                    // de signal (>2000 m entre deux pings espacés de 3–10 s).
                    if ($seg >= 8 && $seg <= 2000) {
                        $dist += (int) round($seg);
                    }
                    \Cache::store('redis')->put($trackKey, ['last_lat' => $lat, 'last_lng' => $lng, 'dist_m' => $dist], 7200);
                } else {
                    // Premier point du trajet → ancrage initial (distance = 0).
                    \Cache::store('redis')->put($trackKey, ['last_lat' => $lat, 'last_lng' => $lng, 'dist_m' => 0], 7200);
                }
            } catch (\Throwable $e) {
                // Redis indisponible : on ignore l'accumulation (la complétion retombera sur l'estimation).
            }
        }

        // Le broadcast Pusher/Reverb reste instantané — le passager voit toujours
        // la voiture bouger en temps réel, indépendamment de la persistance SQL.
        if ($ride) {
            rescue(fn () => broadcast(new DriverLocationUpdated(
                $ride->id,
                [
                    'lat'        => $lat,
                    'lng'        => $lng,
                    'updated_at' => now()->toIso8601String(),
                ]
            )));
        }

        return response()->json([
            'ok'         => true,
            'user_id'    => $driver->id,
            'lat'        => $lat,
            'lng'        => $lng,
            'updated_at' => $driver->last_location_at,
        ]);
    }


    public function passengerRideDriverLocation(int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::findOrFail($id);
        if ($ride->rider_id !== $user->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }
        if (!$ride->driver_id) {
            return response()->json(['message' => 'No driver assigned'], 422);
        }

        $driver = User::find($ride->driver_id);
        if (!$driver) {
            return response()->json(['message' => 'Chauffeur non trouvé'], 404);
        }

        return response()->json([
            'driver_id' => $driver->id,
            'lat' => $driver->last_lat,
            'lng' => $driver->last_lng,
            'updated_at' => $driver->last_location_at,
        ]);
    }

    public function passengerRideWaitAssignment(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::with('driver')->findOrFail($id);
        if ($ride->rider_id !== $user->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }

        $timeoutSeconds = (int) $request->query('timeout', 25);
        $timeoutSeconds = max(5, min($timeoutSeconds, 60));
        $sleepMicroseconds = 500000; // 0.5s
        $iterations = (int) ceil(($timeoutSeconds * 1000000) / $sleepMicroseconds);

        for ($i = 0; $i < $iterations; $i++) {
            $ride->refresh();
            if ($ride->driver_id) {
                $driver = $ride->driver_id ? User::find($ride->driver_id) : null;
                return response()->json([
                    'id' => $ride->id,
                    'status' => $ride->status,
                    'driver' => $this->formatPassenger($driver),
                    'passenger_name' => $ride->passenger_name,
                    'passenger_phone' => $ride->passenger_phone,
                    'stop_started_at' => $ride->stop_started_at,
                    'total_stop_duration_s' => $ride->total_stop_duration_s,
                    ...$this->calculateRideFareBreakdown($ride),
                ]);
            }
            usleep($sleepMicroseconds);
        }

        return response()->json(null, 204);
    }

    protected function offerRideToNextDriver(Ride $ride, array $extraExclude = []): ?User
    {
        if ($ride->pickup_lat === null || $ride->pickup_lng === null) {
            return null;
        }

        $excludeIds = $this->buildDriverExcludeList($ride, $extraExclude);
        $candidate = $this->findDriverCandidate($ride->pickup_lat, $ride->pickup_lng, $excludeIds);

        // Suppression du fallback global : seuls les chauffeurs dans les 10km sont éligibles.
        if (!$candidate) {
            return null;
        }

        $ride->offered_driver_id = $candidate?->id;
        $ride->save();

        return $candidate;
    }

    protected function buildDriverExcludeList(Ride $ride, array $extraExclude = []): array
    {
        $declined = $ride->declined_driver_ids ?? [];
        $ids = array_merge(
            $extraExclude,
            is_array($declined) ? $declined : [],
            $ride->driver_id ? [$ride->driver_id] : []
        );

        $ids = array_filter(array_map(fn($id) => $id ? (int) $id : null, $ids));

        return array_values(array_unique($ids));
    }

    public function nearbyDrivers(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:50'],
        ]);

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $radius = (float) ($data['radius'] ?? config('app.search_radius_km', 10.0));

        $earthRadiusKm = 6371.0;
        $distanceFormula = "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$lat} - users.last_lat) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS(users.last_lat)) *
                    POWER(SIN(RADIANS({$lng} - users.last_lng) / 2), 2)
                )
            )
        )";

        $drivers = User::query()
            ->where('role', 'driver')
            ->where('is_online', true)
            ->where('is_active', true)
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->where('driver_profiles.subscription_remaining_rides', '>', 0)
            ->whereNotNull('users.last_lat')
            ->whereNotNull('users.last_lng')
            ->whereRaw("{$distanceFormula} <= ?", [$radius])
            ->select('users.id', 'users.last_lat as lat', 'users.last_lng as lng', 'users.last_location_at', 'driver_profiles.vehicle_type')
            ->selectRaw("{$distanceFormula} as distance_km")
            ->orderByRaw("{$distanceFormula} ASC")
            ->limit(10)
            ->get();

        return response()->json([
            'drivers' => $drivers,
            'count' => $drivers->count(),
        ]);
    }

    protected function findDriverCandidate(float $pickupLat, float $pickupLng, array $excludeIds = []): ?User
    {
        $earthRadiusKm = 6371.0;
        $searchRadiusKm = (float) config('app.search_radius_km', 10.0);

        // Calcul de la distance à l'aide de la formule Haversine
        $distanceFormula = "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$pickupLat} - users.last_lat) / 2), 2) +
                    COS(RADIANS({$pickupLat})) * COS(RADIANS(users.last_lat)) *
                    POWER(SIN(RADIANS({$pickupLng} - users.last_lng) / 2), 2)
                )
            )
        )";

        $query = User::query()
            ->where('role', 'driver')
            ->where('is_active', true)
            ->where('is_online', true)
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->where('driver_profiles.subscription_remaining_rides', '>', 0)
            ->whereNotNull('users.last_lat')
            ->whereNotNull('users.last_lng')
            ->whereRaw("{$distanceFormula} <= ?", [$searchRadiusKm])
            ->select('users.*')
            ->selectRaw("{$distanceFormula} as distance_km")
            ->orderByRaw("{$distanceFormula} ASC")
            ->orderByDesc('users.last_location_at')
            ->orderBy('users.id');

        if (!empty($excludeIds)) {
            $query->whereNotIn('users.id', $excludeIds);
        }

        return $query->first();
    }

    protected function findDriverFallback(array $excludeIds = []): ?User
    {
        $query = User::query()
            ->where('role', 'driver')
            ->where('is_active', true)
            ->where('is_online', true)
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->where('driver_profiles.subscription_remaining_rides', '>', 0)
            ->select('users.*')
            ->orderByDesc('users.last_location_at')
            ->orderBy('users.id');

        if (!empty($excludeIds)) {
            $query->whereNotIn('users.id', $excludeIds);
        }

        return $query->first();
    }

    protected function formatPassenger(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'photo' => $user->photo,
        ];
    }

    /**
     * Update driver's vehicle information
     */
    public function updateVehicle(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'vehicle_make' => ['required', 'string', 'max:100'],
            'vehicle_model' => ['required', 'string', 'max:100'],
            'vehicle_year' => ['nullable', 'string', 'max:4'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'license_plate' => ['required', 'string', 'max:20'],
            'vehicle_type' => ['nullable', 'string', 'in:sedan,suv,van,compact'],
        ]);

        // Get or create driver profile
        $profile = $driver->driverProfile;
        if (!$profile) {
            return response()->json(['message' => 'Profil chauffeur non trouvé'], 404);
        }

        // Update vehicle information
        $profile->vehicle_make = $data['vehicle_make'];
        $profile->vehicle_model = $data['vehicle_model'];
        $profile->vehicle_year = $data['vehicle_year'] ?? null;
        $profile->vehicle_color = $data['vehicle_color'] ?? null;
        $profile->license_plate = $data['license_plate'];
        $profile->vehicle_type = $data['vehicle_type'] ?? 'sedan';
        $profile->save();

        return response()->json([
            'success' => true,
            'message' => 'Informations du véhicule mises à jour avec succès',
            'vehicle' => [
                'make' => $profile->vehicle_make,
                'model' => $profile->vehicle_model,
                'year' => $profile->vehicle_year,
                'color' => $profile->vehicle_color,
                'license_plate' => $profile->license_plate,
                'type' => $profile->vehicle_type,
            ],
        ]);
    }

    /**
     * Start a stop/wait period (Driver action)
     */
    public function startStop(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);

        if ($ride->driver_id !== ($driver?->id) || $ride->status !== 'ongoing') {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        if ($ride->stop_started_at) {
            return response()->json(['message' => 'Stop already started'], 422);
        }

        $ride->stop_started_at = now();
        $ride->save();

        rescue(fn () => broadcast(new RideStopUpdated($ride)));

        return response()->json(['ok' => true, 'stop_started_at' => $ride->stop_started_at]);
    }

    /**
     * End a stop/wait period (Driver action)
     */
    public function endStop(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);

        if ($ride->driver_id !== ($driver?->id) || $ride->status !== 'ongoing' || !$ride->stop_started_at) {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        $duration = (int) now()->diffInSeconds($ride->stop_started_at, true);
        $ride->total_stop_duration_s += $duration;
        $ride->stop_started_at = null;
        $ride->save();

        rescue(fn () => broadcast(new RideStopUpdated($ride)));

        return response()->json(['ok' => true, 'total_stop_duration_s' => $ride->total_stop_duration_s]);
    }

    /**
     * Get pricing configuration (cached)
     */
    protected function getPricingConfig()
    {
        return Cache::remember('pricing.config', 60, function () {
            $s = PricingSetting::query()->first();
            return [
                'base_fare' => (int) ($s?->base_fare ?? 700),
                'per_km' => (int) ($s?->per_km ?? 200),
                'per_min' => (int) ($s?->per_min ?? 5),
                'min_fare' => (int) ($s?->min_fare ?? 1000),
                'luggage_unit_price' => (int) ($s?->luggage_unit_price ?? 500),
                'stop_rate_per_min' => (int) ($s?->stop_rate_per_min ?? 5),
                'weather' => [
                    'enabled' => (bool) ($s?->weather_mode_enabled ?? false),
                    'multiplier' => (float) ($s?->weather_multiplier ?? 1.0),
                ],
                'night' => [
                    'multiplier' => (float) ($s?->night_multiplier ?? 1.0),
                    'start_time' => substr((string) ($s?->night_start_time ?? '22:00'), 0, 5),
                    'end_time' => substr((string) ($s?->night_end_time ?? '06:00'), 0, 5),
                ],
                'peak_hours' => [
                    'enabled' => (bool) ($s?->peak_hours_enabled ?? false),
                    'multiplier' => (float) ($s?->peak_hours_multiplier ?? 1.0),
                    'start_time' => substr((string) ($s?->peak_hours_start_time ?? '17:00'), 0, 5),
                    'end_time' => substr((string) ($s?->peak_hours_end_time ?? '20:00'), 0, 5),
                ],
                'pickup_grace_period_m' => (int) ($s?->pickup_grace_period_m ?? 5),
                'pickup_waiting_rate_per_min' => (int) ($s?->pickup_waiting_rate_per_min ?? 10),
                'out_of_city' => [
                    'enabled' => (bool) ($s?->out_of_city_enabled ?? false),
                    'multiplier' => (float) ($s?->out_of_city_multiplier ?? 1.5),
                    'min_fare' => (int) ($s?->out_of_city_min_fare ?? 1500),
                    'inner_city_lat' => (float) ($s?->inner_city_lat ?? 6.4969),
                    'inner_city_lng' => (float) ($s?->inner_city_lng ?? 2.6289),
                    'inner_city_radius_km' => (int) ($s?->inner_city_radius_km ?? 15),
                ],
            ];
        });
    }

    /**
     * Calculate live fare breakdown for a ride
     */
    protected function calculateRideFareBreakdown(Ride $ride)
    {
        // Course terminée : source de vérité = breakdown calculé et persisté à la complétion.
        // Recalculer ici divergeait (multiplicateurs réévalués à la consultation, promo/arrondi
        // ignorés) → le reçu chauffeur affichait un montant différent du débit réel.
        if ($ride->status === 'completed' && is_array($ride->breakdown) && isset($ride->breakdown['total_fare'])) {
            $b = $ride->breakdown;
            $baseFare = (int) ($b['base_fare'] ?? 0);
            $trajectoryFare = (int) ($b['trajectory_fare'] ?? 0);
            $stopFare = (int) ($b['stop_fare'] ?? 0);
            $pickupWaitingFare = (int) ($b['pickup_waiting_fare'] ?? 0);

            return [
                'base_fare' => $baseFare,
                'distance_fare' => max(0, $trajectoryFare - $baseFare),
                'time_fare' => (int) ($b['time_fare'] ?? 0),
                'ride_minutes' => (int) ($b['ride_minutes'] ?? 0),
                'per_min_rate' => (int) ($b['per_min_rate'] ?? 0),
                'luggage_fare' => (int) ($b['luggage_fare'] ?? 0),
                'delivery_fare' => (int) ($b['delivery_fare'] ?? 0),
                'wait_fare' => $stopFare + $pickupWaitingFare,
                'duration_fare' => $stopFare + $pickupWaitingFare, // Alias for app compatibility
                'pickup_waiting_fare' => $pickupWaitingFare,
                'stop_waiting_fare' => $stopFare,
                'original_fare' => (int) ($b['original_fare'] ?? 0),
                'discount_amount' => (int) round((float) ($b['discount_amount'] ?? 0)),
                'total_fare' => (int) ($b['total_fare'] ?? $ride->fare_amount ?? 0),
                'wait_duration_m' => 0,
            ];
        }

        $pricing = $this->getPricingConfig();

        // 1. Calculate trajectory price (Base + Distance)
        $distanceKm = ($ride->distance_m ?? 0) / 1000.0;
        $baseFare = (int) $pricing['base_fare'];
        $distanceFare = (int) round($distanceKm * $pricing['per_km']);

        $trajectoryPrice = $baseFare + $distanceFare;

        // Apply multipliers to trajectory only
        if ($ride->vehicle_type === 'vip') {
            $trajectoryPrice *= 1.5;
        }

        if ($pricing['peak_hours']['enabled'] && $this->isCurrentlyInTimeRange($pricing['peak_hours']['start_time'], $pricing['peak_hours']['end_time'])) {
            $trajectoryPrice *= $pricing['peak_hours']['multiplier'];
        }

        if ($pricing['weather']['enabled']) {
            $trajectoryPrice *= $pricing['weather']['multiplier'];
        }

        if ($pricing['night']['multiplier'] > 1.0 && $this->isCurrentlyInTimeRange($pricing['night']['start_time'], $pricing['night']['end_time'])) {
            $trajectoryPrice *= $pricing['night']['multiplier'];
        }

        // Ensure trajectory meets minimum fare
        $trajectoryPrice = max($pricing['min_fare'], (int) round($trajectoryPrice));

        // 2. Calculate stop price
        $totalStopDuration = (int) ($ride->total_stop_duration_s ?? 0);
        if ($ride->stop_started_at) {
            $totalStopDuration += (int) now()->diffInSeconds($ride->stop_started_at, true);
        }

        $stopMinutes = floor($totalStopDuration / 60.0);
        $stopPrice = (int) ($stopMinutes * ($pricing['stop_rate_per_min'] ?? 5));

        // 3. Calculate pickup waiting price
        $pickupWaitingPrice = 0;
        $pickupWaitMinutes = 0;
        if ($ride->arrived_at) {
            $endWait = $ride->started_at ?? now();
            $waitSeconds = (int) $endWait->diffInSeconds($ride->arrived_at, true);
            $graceSeconds = ($pricing['pickup_grace_period_m'] ?? 5) * 60;

            if ($waitSeconds > $graceSeconds) {
                $pickupWaitMinutes = floor(($waitSeconds - $graceSeconds) / 60.0);
                $pickupWaitingPrice = (int) ($pickupWaitMinutes * ($pricing['pickup_waiting_rate_per_min'] ?? 10));
            }
        }

        // 3bis. Temps de course après prise en charge (per_min, hors arrêts déjà facturés)
        $timeFare = 0;
        $rideMinutes = 0;
        if ($ride->started_at) {
            $rideEnd = $ride->completed_at ?? now();
            $rideSeconds = (int) $rideEnd->diffInSeconds($ride->started_at, true);
            $rideSeconds = max(0, $rideSeconds - $totalStopDuration);
            $rideMinutes = (int) floor($rideSeconds / 60.0);
            $timeFare = (int) ($rideMinutes * ($pricing['per_min'] ?? 5));
        }

        $totalFare = $trajectoryPrice + $timeFare + $stopPrice + $pickupWaitingPrice;

        // 4. Luggage Fee
        $luggageCount = (int) ($ride->luggage_count ?? ($ride->has_baggage ? 1 : 0));
        $luggagePrice = $luggageCount * ($pricing['luggage_unit_price'] ?? 500);
        $totalFare += $luggagePrice;
        $deliveryBreakdown = ['size_fee' => 0, 'fragile_fee' => 0, 'weight_fee' => 0, 'total' => 0];
        if ($ride->service_type === 'livraison') {
            $deliveryBreakdown = $this->deliveryPricing->breakdown(
                $ride->package_size,
                $ride->package_weight,
                (bool) $ride->is_fragile
            );
            $totalFare += $deliveryBreakdown['total'];
        }

        return [
            'base_fare' => $baseFare,
            'distance_fare' => $distanceFare,
            'time_fare' => $timeFare,
            'ride_minutes' => $rideMinutes,
            'per_min_rate' => (int) ($pricing['per_min'] ?? 5),
            'luggage_fare' => $luggagePrice,
            'delivery_fare' => $deliveryBreakdown['total'],
            'delivery_fee_breakdown' => $deliveryBreakdown,
            'wait_fare' => $stopPrice + $pickupWaitingPrice,
            'duration_fare' => $stopPrice + $pickupWaitingPrice, // Alias for app compatibility
            'pickup_waiting_fare' => $pickupWaitingPrice,
            'stop_waiting_fare' => $stopPrice,
            'total_fare' => $totalFare,
            'wait_duration_m' => (int) ($stopMinutes + $pickupWaitMinutes),
        ];
    }

    /**
     * Check if current time is within a range (handles overnight ranges)
     */
    protected function isCurrentlyInTimeRange(string $start, string $end): bool
    {
        $nowTime = now()->format('H:i');
        if ($start <= $end) {
            return $nowTime >= $start && $nowTime <= $end;
        } else {
            return $nowTime >= $start || $nowTime <= $end;
        }
    }

    /** @return array<string, mixed> */
    protected function deliveryFields(Ride $ride): array
    {
        if ($ride->service_type !== 'livraison') {
            return [];
        }

        return [
            'recipient_name' => $ride->recipient_name,
            'recipient_phone' => $ride->recipient_phone,
            'package_description' => $ride->package_description,
            'package_size' => $ride->package_size,
            'package_weight' => $ride->package_weight,
            'is_fragile' => (bool) $ride->is_fragile,
        ];
    }

    protected function deliveryCodeForPassenger(Ride $ride): ?string
    {
        if ($ride->service_type !== 'livraison' || ! $ride->delivery_code_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($ride->delivery_code_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function computeFareFromDistance(
        float $distanceM,
        string $vehicleType = 'standard',
        int $luggageCount = 0,
        ?float $pLat = null,
        ?float $pLng = null,
        ?float $dLat = null,
        ?float $dLng = null,
        int $durationS = 0
    ): int {
        $pricing = $this->getPricingConfig();
        $km = $distanceM / 1000.0;

        $price = (float) ($pricing['base_fare'] ?? 500) + ((float) ($pricing['per_km'] ?? 250) * $km);

        if ($vehicleType === 'vip') {
            $price *= 1.5;
        }

        // --- Majorations Dynamiques ---

        // 1. Hors Zone (Porto-Novo -> Cotonou par exemple)
        $outOfCity = $pricing['out_of_city'] ?? null;
        if ($outOfCity && ($outOfCity['enabled'] ?? false) && $pLat && $pLng && $dLat && $dLng) {
            $distPickup = $this->calculateDistance($pLat, $pLng, $outOfCity['inner_city_lat'], $outOfCity['inner_city_lng']);
            $distDropoff = $this->calculateDistance($dLat, $dLng, $outOfCity['inner_city_lat'], $outOfCity['inner_city_lng']);

            if ($distPickup > $outOfCity['inner_city_radius_km'] || $distDropoff > $outOfCity['inner_city_radius_km']) {
                $price *= (float) $outOfCity['multiplier'];
                // On ajuste aussi le tarif minimum si spécifié
                if (! empty($outOfCity['min_fare'])) {
                    $pricing['min_fare'] = max($pricing['min_fare'], $outOfCity['min_fare']);
                }
            }
        }

        // 2. Heures de pointe
        $peak = $pricing['peak_hours'];
        if ($peak['enabled'] && $this->isCurrentlyInTimeRange($peak['start_time'], $peak['end_time'])) {
            $price *= (float) $peak['multiplier'];
        }

        // 3. Météo
        $weather = $pricing['weather'];
        if ($weather['enabled']) {
            $price *= (float) $weather['multiplier'];
        }

        // 4. Nuit
        $night = $pricing['night'];
        if ($night['multiplier'] > 1.0 && $this->isCurrentlyInTimeRange($night['start_time'], $night['end_time'])) {
            $price *= (float) $night['multiplier'];
        }

        $price = max((float) $pricing['min_fare'], round($price));

        // Tarif au temps estimé (per_min × durée prévue) — même structure qu'à la complétion
        if ($durationS > 0) {
            $price += floor($durationS / 60.0) * (int) ($pricing['per_min'] ?? 5);
        }

        $price += $luggageCount * ($pricing['luggage_unit_price'] ?? 500);

        return (int) $price;
    }

    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * SEC-14: borne absolue + cohérence avec distance à vol d'oiseau pickup → dropoff.
     */
    protected function clampCompletionDistanceMeters(Ride $ride, int $reported): int
    {
        $reported = max(0, min($reported, 600_000));

        $pickupLat = $ride->pickup_lat;
        $pickupLng = $ride->pickup_lng;
        $dropLat = $ride->dropoff_lat;
        $dropLng = $ride->dropoff_lng;

        if ($pickupLat === null || $pickupLng === null || $dropLat === null || $dropLng === null) {
            return min($reported, 400_000);
        }

        $straightM = (int) round($this->haversineDistance(
            (float) $pickupLat,
            (float) $pickupLng,
            (float) $dropLat,
            (float) $dropLng
        ));

        if ($straightM <= 0) {
            return min($reported, 400_000);
        }

        $maxAllowed = (int) max($straightM * 2.6 + 10_000, $straightM + 5_000);
        $maxAllowed = min($maxAllowed, 600_000);

        if ($reported > $maxAllowed) {
            \Log::warning('complete: distance_m clamped', [
                'ride_id' => $ride->id,
                'reported' => $reported,
                'max_allowed' => $maxAllowed,
                'straight_m' => $straightM,
            ]);

            return $maxAllowed;
        }

        return $reported;
    }

    /**
     * Calculate distance between two coordinates in KM (Haversine)
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Calcule la distance orthodromique (Haversine) entre deux points GPS, en mètres.
     * Utilisé comme fallback quand OSRM est indisponible.
     */
    private function haversineDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->calculateDistance($lat1, $lng1, $lat2, $lng2) * 1000.0;
    }
}
