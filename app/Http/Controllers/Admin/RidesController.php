<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Ride;
use App\Events\RideCancelled;
use App\Events\RideRequested;
use App\Events\RideAccepted;
use App\Events\RideReassigned;
use App\Models\User;
use App\Services\FcmService;
use App\Services\RideService;
use App\Services\RideCompletionService;

class RidesController extends Controller
{
    protected $rideService;

    public function __construct(RideService $rideService)
    {
        $this->rideService = $rideService;
    }
    public function index(Request $request)
    {
        $status = $request->query('status');
        $driverId = $request->query('driver_id');
        $passengerId = $request->query('passenger_id');
        $reference = $request->query('reference');
        $serviceType = $request->query('service_type');
        $from = $request->query('from');
        $to = $request->query('to');
        $perPage = (int) $request->query('per_page', 20);
        $perPage = min(max($perPage, 1), 100);

        $query = Ride::query()
            ->with([
                'driver:id,name,phone,vehicle_number',
                'rider:id,name,phone',
            ])
            ->orderByDesc('id');

        if ($status) {
            $query->where('status', $status);
        }
        if ($driverId) {
            $query->where('driver_id', $driverId);
        }
        if ($passengerId) {
            $query->where('rider_id', $passengerId);
        }
        if ($reference) {
            $query->where('id', $reference);
        }
        if (in_array($serviceType, ['course', 'livraison'], true)) {
            $query->where('service_type', $serviceType);
        }
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $rides = $query->paginate($perPage);

        $rides->getCollection()->transform(function (Ride $ride) {
            return [
                'id' => $ride->id,
                'status' => $ride->status,
                'fare' => (int) ($ride->fare_amount ?? 0),
                'distance_m' => (int) ($ride->distance_m ?? 0),
                'duration_s' => (int) ($ride->duration_s ?? 0),
                'pickup_address' => $ride->pickup_address,
                'pickup_lat' => (float) $ride->pickup_lat,
                'pickup_lng' => (float) $ride->pickup_lng,
                'dropoff_address' => $ride->dropoff_address,
                'dropoff_lat' => (float) $ride->dropoff_lat,
                'dropoff_lng' => (float) $ride->dropoff_lng,
                'created_at' => $ride->created_at,
                'accepted_at' => $ride->accepted_at,
                'started_at' => $ride->started_at,
                'completed_at' => $ride->completed_at,
                'cancelled_at' => $ride->cancelled_at,
                'declined_driver_ids' => $ride->declined_driver_ids ?? [],
                'service_type' => $ride->service_type ?? 'course',
                'recipient_name' => $ride->recipient_name,
                'recipient_phone' => $ride->recipient_phone,
                'package_description' => $ride->package_description,
                'package_size' => $ride->package_size,
                'package_weight' => $ride->package_weight,
                'is_fragile' => (bool) $ride->is_fragile,
                'driver' => $ride->driver ? [
                    'id' => $ride->driver->id,
                    'name' => $ride->driver->name,
                    'phone' => $ride->driver->phone,
                    'vehicle_number' => $ride->driver->vehicle_number,
                ] : null,
                'passenger' => $ride->rider ? [
                    'id' => $ride->rider->id,
                    'name' => $ride->rider->name,
                    'phone' => $ride->rider->phone,
                ] : null,
            ];
        });

        return response()->json($rides);
    }

    public function byPassenger(Request $request, int $id)
    {
        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $query = Ride::query()
            ->leftJoin('users as drivers', 'drivers.id', '=', 'rides.driver_id')
            ->where('rider_id', $id)
            ->select('rides.*', 'drivers.name as driver_name');
        if ($status) {
            $query->where('status', $status);
        }

        $rides = $query->orderByDesc('rides.id')->paginate($perPage);

        return response()->json($rides);
    }

    public function statusBreakdown(Request $request)
    {
        $statuses = Ride::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $declineData = Ride::whereNotNull('declined_driver_ids')
            ->select('id', 'pickup_address', 'dropoff_address', 'declined_driver_ids', 'updated_at')
            ->orderByDesc('updated_at')
            ->get();

        $totalDeclines = $declineData->reduce(function (int $carry, Ride $ride) {
            return $carry + count($ride->declined_driver_ids ?? []);
        }, 0);

        $recentDeclines = $declineData->map(function (Ride $ride) {
            return [
                'ride_id' => $ride->id,
                'declined_count' => count($ride->declined_driver_ids ?? []),
                'pickup_address' => $ride->pickup_address,
                'dropoff_address' => $ride->dropoff_address,
                'updated_at' => $ride->updated_at,
            ];
        })->take(5);

        $cancelledLast24h = Ride::where('status', 'cancelled')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        $cancelledReasons = Ride::where('status', 'cancelled')
            ->select('cancellation_reason', DB::raw('count(*) as total'))
            ->groupBy('cancellation_reason')
            ->orderByDesc('total')
            ->take(5)
            ->get();

        return response()->json([
            'statuses' => $statuses,
            'declines' => [
                'total_driver_refusals' => $totalDeclines,
                'recent' => $recentDeclines,
            ],
            'cancellations' => [
                'last_24h' => $cancelledLast24h,
                'top_reasons' => $cancelledReasons,
            ],
        ]);
    }

    public function cancel(Request $request, int $id)
    {
        // ... (existing code remains SAME)
        \Log::info('Admin::cancel called', ['ride_id' => $id, 'admin_id' => auth()->id()]);

        $ride = Ride::findOrFail($id);

        if (in_array($ride->status, ['completed', 'cancelled'])) {
            \Log::warning('Admin::cancel failed - already completed or cancelled', ['ride_id' => $id, 'status' => $ride->status]);
            return response()->json(['message' => 'Impossible d\'annuler une course déjà terminée ou annulée.'], 422);
        }

        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = 'Annulation par l\'administrateur';
        $ride->save();

        \Log::info('Admin::cancel success', ['ride_id' => $id]);

        rescue(fn () => broadcast(new RideCancelled($ride, 'admin', auth()->user())));

        return response()->json(['ok' => true, 'message' => 'Course annulée avec succès.']);
    }

    /**
     * Valide (termine) manuellement une course depuis le dashboard.
     * Utile en récupération de crash : si l'app chauffeur a planté pendant une
     * course en cours, l'admin peut la clôturer pour débloquer chauffeur + passager
     * (tarif, commissions et wallet calculés exactement comme la complétion normale).
     */
    public function complete(int $id, RideCompletionService $completionService)
    {
        \Log::info('Admin::complete called', ['ride_id' => $id, 'admin_id' => auth()->id()]);

        $ride = Ride::with('driver')->findOrFail($id);

        if (in_array($ride->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cette course est déjà terminée ou annulée.'], 422);
        }

        // On ne valide que les courses réellement en cours (passager pris en charge).
        if (!in_array($ride->status, ['pickup', 'ongoing'])) {
            return response()->json([
                'message' => "Seule une course démarrée (en cours) peut être validée. Statut actuel : {$ride->status}.",
            ], 422);
        }

        if (!$ride->driver_id || !$ride->driver) {
            return response()->json(['message' => "Impossible de valider : aucun chauffeur n'est assigné à cette course."], 422);
        }

        // Forcer le statut 'ongoing' attendu par la logique de complétion
        // (une course en 'pickup' est déjà en route côté terrain).
        if ($ride->status !== 'ongoing') {
            $ride->status = 'ongoing';
            $ride->save();
        }

        try {
            $result = $completionService->complete($ride, null);

            \Log::info('Admin::complete success', ['ride_id' => $id, 'fare' => $result['ride']->fare_amount]);

            return response()->json([
                'ok' => true,
                'message' => 'Course validée et terminée avec succès.',
                'ride_id' => $result['ride']->id,
                'status' => $result['ride']->status,
                'fare' => $result['ride']->fare_amount,
            ]);
        } catch (\Exception $e) {
            \Log::error('Admin::complete FAILED', ['ride_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Échec de la validation de la course.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tente de géocoder une adresse via Mapbox.
     * Retourne [lat, lng] ou [null, null] en cas d'échec.
     */
    private function geocodeAddress(string $address): array
    {
        try {
            $token = env('MAPBOX_TOKEN');
            if (!$token) {
                Log::warning('Admin Geocoding: MAPBOX_TOKEN not configured');
                return [null, null];
            }

            $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($address) . ".json";
            $response = Http::timeout(4)->get($url, [
                'access_token' => $token,
                'limit' => 1,
                'country' => 'bj',
                'language' => 'fr',
            ]);

            if ($response->ok()) {
                $features = $response->json()['features'] ?? [];
                if (!empty($features)) {
                    $center = $features[0]['center'] ?? null;
                    if ($center && count($center) === 2) {
                        Log::info("Admin Geocoding OK: '{$address}' => [{$center[1]}, {$center[0]}]");
                        return [(float) $center[1], (float) $center[0]]; // [lat, lng]
                    }
                }
            }

            Log::warning("Admin Geocoding: No results for '{$address}'");
        } catch (\Exception $e) {
            Log::error("Admin Geocoding Error: " . $e->getMessage());
        }

        return [null, null];
    }

    /**
     * Crée manuellement une course par l'administrateur.
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'pickup_address' => 'required|string|max:255',
                'dropoff_address' => 'required|string|max:255',
                'fare_amount' => 'required|numeric|min:1',
                'passenger_name' => 'nullable|string|max:255',
                'passenger_phone' => 'nullable|string|max:255',
                'vehicle_type' => 'nullable|string|in:standard,vip',
                'has_baggage' => 'nullable|boolean',
                'pickup_lat' => 'nullable|numeric',
                'pickup_lng' => 'nullable|numeric',
                'dropoff_lat' => 'nullable|numeric',
                'dropoff_lng' => 'nullable|numeric',
            ]);

            // Auto-geocode pickup address if coordinates are not provided
            $pickupLat = $data['pickup_lat'] ?? null;
            $pickupLng = $data['pickup_lng'] ?? null;
            $dropoffLat = $data['dropoff_lat'] ?? null;
            $dropoffLng = $data['dropoff_lng'] ?? null;

            if (!$pickupLat || !$pickupLng) {
                [$pickupLat, $pickupLng] = $this->geocodeAddress($data['pickup_address']);
            }
            if (!$dropoffLat || !$dropoffLng) {
                [$dropoffLat, $dropoffLng] = $this->geocodeAddress($data['dropoff_address']);
            }

            $ride = Ride::create([
                'rider_id' => $request->user()->id,
                'status' => 'requested',
                'fare_amount' => (int) $data['fare_amount'],
                'pickup_address' => $data['pickup_address'],
                'dropoff_address' => $data['dropoff_address'],
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'dropoff_lat' => $dropoffLat,
                'dropoff_lng' => $dropoffLng,
                'passenger_name' => $data['passenger_name'],
                'passenger_phone' => $data['passenger_phone'],
                'vehicle_type' => $data['vehicle_type'] ?? 'standard',
                'has_baggage' => $data['has_baggage'] ?? false,
                'currency' => 'XOF',
                'payment_method' => 'cash',
                'declined_driver_ids' => [],
            ]);

            $this->rideService->notifyNearbyDrivers($ride);

            return response()->json([
                'ok' => true,
                'ride' => $ride
            ], 201);
        } catch (\Exception $e) {
            \Log::error("Manual Ride Creation Error: " . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);
            $payload = ['message' => 'Erreur lors de la création de la course.'];
            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }

            return response()->json($payload, 422);
        }
    }

    /**
     * Assigne manuellement un chauffeur à une course.
     */
    public function assign(Request $request, int $id)
    {
        $data = $request->validate([
            'driver_id' => 'required|exists:users,id',
        ]);

        $ride = Ride::findOrFail($id);
        $driver = User::findOrFail($data['driver_id']);

        if (!$driver->isDriver()) {
            return response()->json(['message' => 'L\'utilisateur n\'est pas un chauffeur.'], 422);
        }

        if (!in_array($ride->status, ['requested', 'accepted', 'arrived'])) {
            return response()->json(['message' => 'La course ne peut plus être réassignée (déjà démarrée ou terminée).'], 422);
        }

        $oldDriverId = $ride->driver_id ? (int) $ride->driver_id : null;

        $ride->status = 'accepted';
        $ride->driver_id = $driver->id;
        $ride->offered_driver_id = $driver->id;
        $ride->accepted_at = now();
        // La réassignation repart de l'acceptation : l'attente de l'ancien chauffeur ne doit pas être facturée
        $ride->arrived_at = null;
        $ride->save();

        // Distance d'approche — enrichissement best-effort, ne doit JAMAIS bloquer la réassignation.
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
            Log::warning('approach_distance_m non enregistrée (assign, non bloquant): ' . $e->getMessage());
        }

        rescue(fn () => broadcast(new RideAccepted($ride->load('driver'))));

        // Temps réel chauffeurs : l'ancien libère son écran, le nouveau charge la course sans redémarrer
        rescue(fn () => broadcast(new RideReassigned($ride, (int) $driver->id, $oldDriverId)));

        try {
            $fcm = app(FcmService::class);
            $fcm->sendToUser(
                $driver,
                'Nouvelle course assignée',
                'Le support vous a assigné une course' . ($ride->pickup_address ? " — départ : {$ride->pickup_address}" : '.'),
                ['ride_id' => (string) $ride->id, 'type' => 'ride_assigned']
            );
            if ($oldDriverId && $oldDriverId !== (int) $driver->id) {
                $oldDriver = User::find($oldDriverId);
                if ($oldDriver) {
                    $fcm->sendToUser(
                        $oldDriver,
                        'Course réassignée',
                        'Cette course a été confiée à un autre chauffeur par le support.',
                        ['ride_id' => (string) $ride->id, 'type' => 'ride_reassigned']
                    );
                }
            }
        } catch (\Exception $e) {
            Log::error('FCM Reassignment Notification Error: ' . $e->getMessage());
        }

        // Notification FCM au passager si mobile (ou info dans le canal socket)
        try {
            if ($ride->rider_id || $ride->passenger_phone) {
                $fcm = app(FcmService::class);
                // Si on a un rider_id, on notifie l'user
                if ($ride->rider_id) {
                    $passenger = User::find($ride->rider_id);
                    if ($passenger) {
                        $fcm->sendToUser(
                            $passenger,
                            "Course assignée !",
                            "Le support a assigné {$driver->name} pour votre course.",
                            ['ride_id' => (string) $ride->id, 'type' => 'ride_accepted']
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error("FCM Manual Assignment Notification Error: " . $e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'message' => 'Chauffeur assigné avec succès.',
            'ride' => $ride
        ]);
    }
}
