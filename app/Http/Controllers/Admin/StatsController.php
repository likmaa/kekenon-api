<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Ride;
use App\Models\User;

class StatsController extends Controller
{
    public function overview(Request $request)
    {
        $now = Carbon::now();
        $startOfDay = $now->copy()->startOfDay();
        $endOfDay = $now->copy()->endOfDay();

        $onlineDrivers = User::query()
            ->where('role', 'driver')
            ->where('is_active', true)
            ->where('is_online', true)
            ->count();

        $activeRides = Ride::query()
            ->whereIn('status', ['requested', 'accepted', 'ongoing'])
            ->count();

        $todayCompletedQuery = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$startOfDay, $endOfDay]);

        $todayCompletedCount = (int) $todayCompletedQuery->count();
        $todayRideRevenue = (int) $todayCompletedQuery->sum('fare_amount');

        // Revenus Externes (Hors-App) du jour
        $todayExternalRevenue = (int) DB::table('wallet_transactions')
            ->where('source', 'external_revenue')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->get()
            ->sum(function($t) {
                $meta = json_decode($t->meta, true);
                return $meta['total_fare'] ?? 0;
            });

        $todayRevenueAmount = $todayRideRevenue + $todayExternalRevenue;

        // CA total (toutes les courses terminées + revenus externes depuis le lancement)
        $totalRideRevenue = (int) Ride::query()->where('status', 'completed')->sum('fare_amount');
        $totalExternalRevenue = (int) DB::table('wallet_transactions')
            ->where('source', 'external_revenue')
            ->get()
            ->sum(function($t) {
                $meta = json_decode($t->meta, true);
                return $meta['total_fare'] ?? 0;
            });

        $totalRevenueAmount = $totalRideRevenue + $totalExternalRevenue;

        // --- Comparaison avec hier (deltas réels) ---
        $startYesterday = $startOfDay->copy()->subDay();
        $endYesterday = $endOfDay->copy()->subDay();
        $yesterdayCompletedQuery = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$startYesterday, $endYesterday]);
        $yesterdayCompletedCount = (int) $yesterdayCompletedQuery->count();
        $yesterdayRevenueAmount = (int) (clone $yesterdayCompletedQuery)->sum('fare_amount');

        // --- KPI 5 : Utilisateurs actifs (passagers ayant commandé sur 30 jours) ---
        $activeUsers30d = (int) Ride::query()
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->whereNotNull('rider_id')
            ->distinct('rider_id')
            ->count('rider_id');

        // --- KPI 6 : Taux d'acceptation (courses du jour ayant trouvé un chauffeur) ---
        $requestedToday = (int) Ride::query()
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();
        $acceptedToday = (int) Ride::query()
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereNotNull('accepted_at')
            ->count();
        $acceptanceRate = $requestedToday > 0
            ? round(($acceptedToday / $requestedToday) * 100, 1)
            : null;

        // --- KPI 8 : Temps moyen d'attribution (created_at -> accepted_at, courses du jour) ---
        $avgAssignmentSec = (float) Ride::query()
            ->whereNotNull('accepted_at')
            ->whereBetween('accepted_at', [$startOfDay, $endOfDay])
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "AVG(strftime('%s', accepted_at) - strftime('%s', created_at)) as avg_sec" : "AVG(TIMESTAMPDIFF(SECOND, created_at, accepted_at)) as avg_sec")
            ->value('avg_sec');
        $avgAssignmentSec = $avgAssignmentSec > 0 ? (int) round($avgAssignmentSec) : null;

        $pctChange = function (int $today, int $yesterday): ?float {
            if ($yesterday <= 0) {
                return $today > 0 ? 100.0 : null;
            }
            return round((($today - $yesterday) / $yesterday) * 100, 1);
        };

        return response()->json([
            // North Star + KPI 1
            'today_completed_rides' => $todayCompletedCount,
            'today_completed_rides_delta_pct' => $pctChange($todayCompletedCount, $yesterdayCompletedCount),
            // KPI 2
            'today_revenue' => [
                'amount' => $todayRevenueAmount,
                'currency' => 'XOF',
            ],
            'today_revenue_delta_pct' => $pctChange($todayRevenueAmount, $yesterdayRevenueAmount),
            'total_revenue' => [
                'amount' => $totalRevenueAmount,
                'currency' => 'XOF',
            ],
            // KPI 3
            'online_drivers' => $onlineDrivers,
            // KPI 4
            'active_rides' => $activeRides,
            // KPI 5
            'active_users_30d' => $activeUsers30d,
            // KPI 6
            'acceptance_rate_pct' => $acceptanceRate,
            'acceptance_rate_basis' => [
                'accepted' => $acceptedToday,
                'requested' => $requestedToday,
            ],
            // KPI 8
            'avg_assignment_seconds' => $avgAssignmentSec,
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * Bloc 2 — Évolution de l'activité : revenus / courses / nouveaux utilisateurs.
     * ?granularity=day (14 derniers jours) | week (8 semaines) | month (6 mois)
     */
    public function trends(Request $request)
    {
        $granularity = $request->input('granularity', 'day');
        if (!in_array($granularity, ['day', 'week', 'month'], true)) {
            $granularity = 'day';
        }

        $monthsFr = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $now = Carbon::now();
        $buckets = [];

        if ($granularity === 'day') {
            for ($i = 13; $i >= 0; $i--) {
                $d = $now->copy()->subDays($i);
                $buckets[] = [
                    'start' => $d->copy()->startOfDay(),
                    'end' => $d->copy()->endOfDay(),
                    'label' => $d->format('d/m'),
                ];
            }
        } elseif ($granularity === 'week') {
            for ($i = 7; $i >= 0; $i--) {
                $d = $now->copy()->subWeeks($i);
                $buckets[] = [
                    'start' => $d->copy()->startOfWeek(),
                    'end' => $d->copy()->endOfWeek(),
                    'label' => 'S' . $d->isoWeek(),
                ];
            }
        } else {
            for ($i = 5; $i >= 0; $i--) {
                $d = $now->copy()->subMonths($i);
                $buckets[] = [
                    'start' => $d->copy()->startOfMonth(),
                    'end' => $d->copy()->endOfMonth(),
                    'label' => $monthsFr[(int) $d->month] . ' ' . $d->format('y'),
                ];
            }
        }

        $series = [];
        foreach ($buckets as $b) {
            $completed = Ride::query()
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$b['start'], $b['end']]);

            $rideRevenue = (int) (clone $completed)->sum('fare_amount');

            $externalRevenue = (int) DB::table('wallet_transactions')
                ->where('source', 'external_revenue')
                ->whereBetween('created_at', [$b['start'], $b['end']])
                ->get()
                ->sum(function($t) {
                    $meta = json_decode($t->meta, true);
                    return $meta['total_fare'] ?? 0;
                });

            $series[] = [
                'label' => $b['label'],
                'revenue' => $rideRevenue + $externalRevenue,
                'rides' => (int) (clone $completed)->count(),
                'new_users' => (int) User::query()
                    ->where('role', 'passenger')
                    ->whereBetween('created_at', [$b['start'], $b['end']])
                    ->count(),
            ];
        }

        return response()->json([
            'granularity' => $granularity,
            'currency' => 'XOF',
            'series' => $series,
        ]);
    }

    /**
     * Bloc 5 — Alertes système classées (critiques / élevées / moyennes),
     * calculées à partir de signaux métier réels.
     */
    public function alerts(Request $request)
    {
        $now = Carbon::now();
        $startOfDay = $now->copy()->startOfDay();
        $endOfDay = $now->copy()->endOfDay();
        $alerts = [];

        $onlineDrivers = User::query()
            ->where('role', 'driver')->where('is_active', true)->where('is_online', true)->count();

        $pendingRides = (int) Ride::query()
            ->where('status', 'requested')
            ->where('created_at', '<=', $now->copy()->subMinutes(5))
            ->count();

        // CRITIQUE : demandes en attente alors qu'aucun chauffeur n'est en ligne
        if ($pendingRides > 0 && $onlineDrivers === 0) {
            $alerts[] = [
                'severity' => 'critique',
                'code' => 'no_driver_online',
                'title' => 'Aucun chauffeur en ligne',
                'detail' => "{$pendingRides} course(s) en attente sans aucun chauffeur connecté.",
            ];
        }

        // ÉLEVÉE : courses en attente depuis > 5 min (zone sous-desservie)
        if ($pendingRides > 0 && $onlineDrivers > 0) {
            $alerts[] = [
                'severity' => 'elevee',
                'code' => 'pending_unassigned',
                'title' => 'Courses sans chauffeur',
                'detail' => "{$pendingRides} course(s) en attente d'attribution depuis plus de 5 min.",
            ];
        }

        // ÉLEVÉE : taux d'annulation du jour élevé
        $createdToday = (int) Ride::query()->whereBetween('created_at', [$startOfDay, $endOfDay])->count();
        $cancelledToday = (int) Ride::query()->whereBetween('cancelled_at', [$startOfDay, $endOfDay])->count();
        if ($createdToday >= 5) {
            $cancelRate = round(($cancelledToday / $createdToday) * 100, 1);
            if ($cancelRate >= 30) {
                $alerts[] = [
                    'severity' => 'elevee',
                    'code' => 'high_cancel_rate',
                    'title' => "Taux d'annulation élevé",
                    'detail' => "{$cancelRate}% des courses du jour ont été annulées.",
                ];
            }
        }

        // MOYENNE : baisse d'activité vs hier
        $todayCompleted = (int) Ride::query()->where('status', 'completed')->whereBetween('completed_at', [$startOfDay, $endOfDay])->count();
        $yCompleted = (int) Ride::query()->where('status', 'completed')
            ->whereBetween('completed_at', [$startOfDay->copy()->subDay(), $endOfDay->copy()->subDay()])->count();
        if ($yCompleted >= 4 && $todayCompleted < ($yCompleted * 0.5)) {
            $alerts[] = [
                'severity' => 'moyenne',
                'code' => 'activity_drop',
                'title' => "Baisse d'activité",
                'detail' => "Courses terminées aujourd'hui ({$todayCompleted}) en net recul vs hier ({$yCompleted}).",
            ];
        }

        // MOYENNE : taux d'acceptation faible
        $acceptedToday = (int) Ride::query()->whereBetween('created_at', [$startOfDay, $endOfDay])->whereNotNull('accepted_at')->count();
        if ($createdToday >= 5) {
            $accRate = round(($acceptedToday / $createdToday) * 100, 1);
            if ($accRate < 70) {
                $alerts[] = [
                    'severity' => 'moyenne',
                    'code' => 'low_acceptance',
                    'title' => "Taux d'acceptation en baisse",
                    'detail' => "Seulement {$accRate}% des courses du jour ont trouvé un chauffeur.",
                ];
            }
        }

        $order = ['critique' => 0, 'elevee' => 1, 'moyenne' => 2];
        usort($alerts, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return response()->json([
            'alerts' => $alerts,
            'counts' => [
                'critique' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'critique')),
                'elevee' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'elevee')),
                'moyenne' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'moyenne')),
            ],
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * Bloc 3 — Monitoring Dispatch + KPI Module Courses (§20.4).
     * Période : aujourd'hui (par défaut) ou ?days=N pour les N derniers jours.
     *
     * Les courses expirées sont gérées par la commande planifiée `rides:expire`
     * (bootstrap/app.php, chaque minute) : une course 'requested' depuis > 10 min
     * est passée en 'cancelled' avec cancellation_reason='timeout_no_driver'.
     * On distingue donc : expirées (timeout_no_driver) / annulées (autres raisons)
     * / sans chauffeur (encore en attente d'attribution).
     */
    public function dispatch(Request $request)
    {
        $now = Carbon::now();
        $days = max(1, min((int) $request->input('days', 1), 90));
        $start = $days === 1 ? $now->copy()->startOfDay() : $now->copy()->subDays($days - 1)->startOfDay();
        $end = $now->copy()->endOfDay();

        // --- Dispatch ---
        $avgAssignmentSec = (float) Ride::query()
            ->whereNotNull('accepted_at')
            ->whereBetween('accepted_at', [$start, $end])
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "AVG(strftime('%s', accepted_at) - strftime('%s', created_at)) as v" : "AVG(TIMESTAMPDIFF(SECOND, created_at, accepted_at)) as v")
            ->value('v');

        $avgPickupSec = (float) Ride::query()
            ->whereNotNull('accepted_at')
            ->whereNotNull('started_at')
            ->whereBetween('started_at', [$start, $end])
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "AVG(strftime('%s', started_at) - strftime('%s', accepted_at)) as v" : "AVG(TIMESTAMPDIFF(SECOND, accepted_at, started_at)) as v")
            ->value('v');

        $refusedRides = (int) Ride::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('declined_driver_ids')
            ->whereRaw(DB::connection()->getDriverName() === 'sqlite' ? "declined_driver_ids != '[]' AND declined_driver_ids IS NOT NULL" : "JSON_LENGTH(declined_driver_ids) > 0")
            ->count();

        // Expirées : auto-annulées par le scheduler `rides:expire` (timeout sans chauffeur)
        $expiredRides = (int) Ride::query()
            ->whereBetween('cancelled_at', [$start, $end])
            ->where('cancellation_reason', 'timeout_no_driver')
            ->count();

        // Annulées : vraies annulations (passager / chauffeur / admin), hors expiration auto
        $cancelledRides = (int) Ride::query()
            ->whereBetween('cancelled_at', [$start, $end])
            ->where(function ($q) {
                $q->where('cancellation_reason', '!=', 'timeout_no_driver')
                    ->orWhereNull('cancellation_reason');
            })
            ->count();

        // Sans chauffeur (en attente) : encore 'requested', non attribuées depuis plus de 5 min
        $noDriverRides = (int) Ride::query()
            ->where('status', 'requested')
            ->whereNull('accepted_at')
            ->where('created_at', '<=', $now->copy()->subMinutes(5))
            ->count();

        // --- KPI Module Courses (§20.4) sur les courses terminées de la période ---
        $completedQuery = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end]);
        $completedCount = (int) (clone $completedQuery)->count();

        $avgDurationSec = (float) Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->whereNotNull('started_at')
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "AVG(strftime('%s', completed_at) - strftime('%s', started_at)) as v" : "AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as v")
            ->value('v');

        $avgDistanceM = (float) (clone $completedQuery)->where('distance_m', '>', 0)->avg('distance_m');
        $avgFare = (float) (clone $completedQuery)->avg('fare_amount');

        $createdInPeriod = (int) Ride::query()->whereBetween('created_at', [$start, $end])->count();
        $cancellationRate = $createdInPeriod > 0
            ? round(($cancelledRides / $createdInPeriod) * 100, 1)
            : null;

        return response()->json([
            'period_days' => $days,
            'dispatch' => [
                'avg_assignment_seconds' => $avgAssignmentSec > 0 ? (int) round($avgAssignmentSec) : null,
                'avg_pickup_seconds' => $avgPickupSec > 0 ? (int) round($avgPickupSec) : null,
                'refused_rides' => $refusedRides,
                'expired_rides' => $expiredRides,
                'cancelled_rides' => $cancelledRides,
                'no_driver_rides' => $noDriverRides,
            ],
            'courses' => [
                'completed_count' => $completedCount,
                'avg_duration_seconds' => $avgDurationSec > 0 ? (int) round($avgDurationSec) : null,
                'avg_distance_m' => $avgDistanceM > 0 ? (int) round($avgDistanceM) : null,
                'avg_fare_amount' => $avgFare > 0 ? (int) round($avgFare) : null,
                'cancellation_rate_pct' => $cancellationRate,
                'currency' => 'XOF',
            ],
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * Détail des courses par catégorie (pour les modals du cockpit), avec filtre de dates.
     * ?category=refused|expired|cancelled|completed|no_driver  &from=YYYY-MM-DD &to=YYYY-MM-DD
     */
    public function dispatchRides(Request $request)
    {
        $category = $request->input('category', 'completed');
        if (!in_array($category, ['refused', 'expired', 'cancelled', 'completed', 'no_driver'], true)) {
            $category = 'completed';
        }
        $now = Carbon::now();
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : $now->copy()->startOfDay();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : $now->copy()->endOfDay();

        $q = Ride::query()->with(['rider:id,name,phone', 'driver:id,name,phone']);
        $dateField = 'created_at';

        switch ($category) {
            case 'refused':
                $q->whereBetween('created_at', [$from, $to])
                    ->whereNotNull('declined_driver_ids')
                    ->whereRaw(DB::connection()->getDriverName() === 'sqlite' ? "declined_driver_ids != '[]' AND declined_driver_ids IS NOT NULL" : "JSON_LENGTH(declined_driver_ids) > 0");
                $dateField = 'created_at';
                break;
            case 'expired':
                $q->where('cancellation_reason', 'timeout_no_driver')->whereBetween('cancelled_at', [$from, $to]);
                $dateField = 'cancelled_at';
                break;
            case 'cancelled':
                $q->whereBetween('cancelled_at', [$from, $to])
                    ->where(function ($x) {
                        $x->where('cancellation_reason', '!=', 'timeout_no_driver')->orWhereNull('cancellation_reason');
                    });
                $dateField = 'cancelled_at';
                break;
            case 'no_driver':
                $q->where('status', 'requested')->whereNull('accepted_at')->whereBetween('created_at', [$from, $to]);
                $dateField = 'created_at';
                break;
            case 'completed':
            default:
                $q->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);
                $dateField = 'completed_at';
                break;
        }

        $rides = $q->orderByDesc($dateField)->limit(500)->get();

        $rows = $rides->map(function ($r) use ($dateField) {
            return [
                'id' => (int) $r->id,
                'passenger_name' => $r->passenger_name ?: ($r->rider->name ?? null),
                'passenger_phone' => $r->passenger_phone ?: ($r->rider->phone ?? null),
                'driver_name' => $r->driver->name ?? null,
                'pickup_address' => $r->pickup_address,
                'dropoff_address' => $r->dropoff_address,
                'fare' => (int) $r->fare_amount,
                'status' => $r->status,
                'cancellation_reason' => $r->cancellation_reason,
                'datetime' => optional($r->{$dateField})->toIso8601String(),
                // Distances en km (approche = chauffeur → client, course = prise en charge → destination)
                'approach_km' => $r->approach_distance_m !== null ? round($r->approach_distance_m / 1000, 2) : null,
                'ride_km' => $r->distance_m !== null ? round($r->distance_m / 1000, 2) : null,
                'duration_min' => $this->rideEffectiveMinutes($r),
            ];
        });

        return response()->json([
            'category' => $category,
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'count' => $rows->count(),
            'currency' => 'XOF',
            'rows' => $rows,
        ]);
    }

    /**
     * Temps de course effectif (minutes) : priorité au breakdown persisté (exclut les arrêts),
     * sinon estimé via started_at → completed_at.
     */
    private function rideEffectiveMinutes(Ride $r): ?int
    {
        if (is_array($r->breakdown) && isset($r->breakdown['ride_minutes'])) {
            return (int) $r->breakdown['ride_minutes'];
        }
        if ($r->started_at && $r->completed_at) {
            return (int) floor($r->completed_at->diffInSeconds($r->started_at, true) / 60);
        }
        return null;
    }

    /**
     * Détail complet d'une course pour la page dédiée du cockpit.
     */
    public function dispatchRideDetail(int $id)
    {
        $r = Ride::with(['rider:id,name,phone', 'driver:id,name,phone'])->findOrFail($id);

        return response()->json([
            'id' => (int) $r->id,
            'status' => $r->status,
            'cancellation_reason' => $r->cancellation_reason,
            'passenger_name' => $r->passenger_name ?: ($r->rider->name ?? null),
            'passenger_phone' => $r->passenger_phone ?: ($r->rider->phone ?? null),
            'driver_name' => $r->driver->name ?? null,
            'driver_phone' => $r->driver->phone ?? null,
            'pickup_address' => $r->pickup_address,
            'dropoff_address' => $r->dropoff_address,
            'pickup_lat' => $r->pickup_lat,
            'pickup_lng' => $r->pickup_lng,
            'dropoff_lat' => $r->dropoff_lat,
            'dropoff_lng' => $r->dropoff_lng,
            'approach_km' => $r->approach_distance_m !== null ? round($r->approach_distance_m / 1000, 2) : null,
            'ride_km' => $r->distance_m !== null ? round($r->distance_m / 1000, 2) : null,
            'duration_min' => $this->rideEffectiveMinutes($r),
            'vehicle_type' => $r->vehicle_type,
            'payment_method' => $r->payment_method,
            'payment_status' => $r->payment_status,
            'fare' => (int) $r->fare_amount,
            'original_fare' => (int) ($r->original_fare_amount ?? $r->fare_amount),
            'discount_amount' => (int) round((float) ($r->discount_amount ?? 0)),
            'breakdown' => is_array($r->breakdown) ? $r->breakdown : null,
            'created_at' => optional($r->created_at)->toIso8601String(),
            'accepted_at' => optional($r->accepted_at)->toIso8601String(),
            'arrived_at' => optional($r->arrived_at)->toIso8601String(),
            'started_at' => optional($r->started_at)->toIso8601String(),
            'completed_at' => optional($r->completed_at)->toIso8601String(),
            'cancelled_at' => optional($r->cancelled_at)->toIso8601String(),
        ]);
    }

    /**
     * §20.8 — Segmentation CRM des passagers.
     * Segments : nouveau (inscrit récemment) · inactif (aucune course récente) ·
     * vip (forte dépense cumulée) · actif (courses récurrentes) · occasionnel.
     */
    public function passengerSegments(Request $request)
    {
        $now = Carbon::now();
        $vipThreshold = (int) config('crm.vip_total_spent', 50000);
        $newDays = (int) config('crm.new_days', 30);
        $inactiveDays = (int) config('crm.inactive_days', 60);
        $activeRides30d = (int) config('crm.active_rides_30d', 2);

        $d30 = $now->copy()->subDays(30)->toDateTimeString();
        $segmentFilter = $request->input('segment'); // optionnel : filtrer la liste
        $limit = max(1, min((int) $request->input('limit', 100), 500));

        $rideAgg = DB::table('rides')
            ->where('status', 'completed')
            ->whereNotNull('rider_id')
            ->selectRaw('rider_id, COUNT(*) as rides_count, COALESCE(SUM(fare_amount),0) as total_spent, MAX(completed_at) as last_ride, SUM(CASE WHEN completed_at >= ? THEN 1 ELSE 0 END) as rides_30d', [$d30]);

        $passengers = DB::table('users')
            ->where('users.role', 'passenger')
            ->leftJoinSub($rideAgg, 'r', 'r.rider_id', '=', 'users.id')
            ->selectRaw('users.id, users.name, users.phone, users.created_at,
                COALESCE(r.rides_count,0) as rides_count,
                COALESCE(r.total_spent,0) as total_spent,
                r.last_ride,
                COALESCE(r.rides_30d,0) as rides_30d')
            ->get();

        $classify = function ($p) use ($now, $vipThreshold, $newDays, $inactiveDays, $activeRides30d) {
            $created = Carbon::parse($p->created_at);
            $lastRide = $p->last_ride ? Carbon::parse($p->last_ride) : null;

            if ($created->gte($now->copy()->subDays($newDays))) {
                return 'nouveau';
            }
            if (!$lastRide || $lastRide->lt($now->copy()->subDays($inactiveDays))) {
                return 'inactif';
            }
            if ((int) $p->total_spent >= $vipThreshold) {
                return 'vip';
            }
            if ((int) $p->rides_30d >= $activeRides30d) {
                return 'actif';
            }
            return 'occasionnel';
        };

        $counts = ['nouveau' => 0, 'actif' => 0, 'vip' => 0, 'inactif' => 0, 'occasionnel' => 0];
        $rows = [];
        foreach ($passengers as $p) {
            $seg = $classify($p);
            $counts[$seg]++;
            if ($segmentFilter && $seg !== $segmentFilter) {
                continue;
            }
            $rows[] = [
                'id' => (int) $p->id,
                'name' => $p->name,
                'phone' => $p->phone,
                'registered_at' => Carbon::parse($p->created_at)->toIso8601String(),
                'rides_count' => (int) $p->rides_count,
                'total_spent' => (int) $p->total_spent,
                'last_activity' => $p->last_ride ? Carbon::parse($p->last_ride)->toIso8601String() : null,
                'segment' => $seg,
            ];
        }

        // tri : dépense décroissante puis activité récente
        usort($rows, fn ($a, $b) => $b['total_spent'] <=> $a['total_spent']);
        $rows = array_slice($rows, 0, $limit);

        return response()->json([
            'currency' => 'XOF',
            'total_passengers' => $passengers->count(),
            'counts' => $counts,
            'rows' => $rows,
            'thresholds' => ['vip_total_spent' => $vipThreshold, 'new_days' => $newDays, 'inactive_days' => $inactiveDays],
        ]);
    }

    /**
     * §20.5 — Score chauffeur (40% activité / 30% satisfaction / 20% ponctualité / 10% discipline)
     * + KPI + classement, sur les N derniers jours (?days=30 par défaut).
     */
    public function driverScores(Request $request)
    {
        $now = Carbon::now();
        $days = max(1, min((int) $request->input('days', 30), 365));
        $start = $now->copy()->subDays($days - 1)->startOfDay();
        $end = $now->copy()->endOfDay();
        $limit = max(1, min((int) $request->input('limit', 50), 200));
        $activityTarget = max(1, (int) $request->input('activity_target', $days * 2)); // ~2 courses/jour

        $completed = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, COUNT(*) as completed_count, COALESCE(SUM(fare_amount),0) as gross, COALESCE(SUM(driver_earnings_amount),0) as earnings')
            ->groupBy('driver_id')->get()->keyBy('driver_id');

        $accepted = Ride::query()
            ->whereNotNull('accepted_at')->whereBetween('accepted_at', [$start, $end])
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, COUNT(*) as accepted_count')
            ->groupBy('driver_id')->get()->keyBy('driver_id');

        $cancelledAfterAccept = Ride::query()
            ->where('status', 'cancelled')->whereNotNull('accepted_at')
            ->whereBetween('cancelled_at', [$start, $end])
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, COUNT(*) as cnt')
            ->groupBy('driver_id')->get()->keyBy('driver_id');

        $pickup = Ride::query()
            ->whereNotNull('accepted_at')->whereNotNull('started_at')
            ->whereBetween('started_at', [$start, $end])
            ->whereNotNull('driver_id')
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "driver_id, AVG(strftime('%s', started_at) - strftime('%s', accepted_at)) as avg_pickup" : "driver_id, AVG(TIMESTAMPDIFF(SECOND, accepted_at, started_at)) as avg_pickup")
            ->groupBy('driver_id')->get()->keyBy('driver_id');

        $ratings = DB::table('ratings')
            ->selectRaw('driver_id, AVG(stars) as avg_stars, COUNT(*) as cnt')
            ->groupBy('driver_id')->get()->keyBy('driver_id');

        $driverIds = collect()->merge($completed->keys())->merge($accepted->keys())->unique()->values();
        if ($driverIds->isEmpty()) {
            return response()->json(['period_days' => $days, 'weights' => ['activite' => 40, 'satisfaction' => 30, 'ponctualite' => 20, 'discipline' => 10], 'drivers' => []]);
        }

        $driverInfo = User::whereIn('id', $driverIds)->where('role', 'driver')->get(['id', 'name', 'phone'])->keyBy('id');

        $list = [];
        foreach ($driverIds as $id) {
            if (!isset($driverInfo[$id])) {
                continue;
            }
            $completedCount = (int) ($completed[$id]->completed_count ?? 0);
            $acceptedCount = (int) ($accepted[$id]->accepted_count ?? 0);
            $cancelCount = (int) ($cancelledAfterAccept[$id]->cnt ?? 0);
            $avgPickup = isset($pickup[$id]) ? (float) $pickup[$id]->avg_pickup : null;
            $ratingCnt = (int) ($ratings[$id]->cnt ?? 0);
            $ratingAvg = $ratingCnt > 0 ? (float) $ratings[$id]->avg_stars : null;

            // Activité (40 pts)
            $activityScore = min($completedCount / $activityTarget, 1.0) * 40;
            // Satisfaction (30 pts) — baseline 4/5 tant qu'il n'y a pas de note
            $r = $ratingCnt >= 1 ? $ratingAvg : 4.0;
            $satisfactionScore = max(0.0, min($r / 5, 1.0)) * 30;
            // Ponctualité (20 pts) — <=5 min plein, >=20 min nul ; neutre 70% si pas de donnée
            if ($avgPickup === null) {
                $punctualityScore = 14.0;
            } else {
                $p = 1 - (($avgPickup - 300) / (1200 - 300));
                $punctualityScore = max(0.0, min($p, 1.0)) * 20;
            }
            // Discipline (10 pts) — taux d'annulation après acceptation
            $cancelRate = $acceptedCount > 0 ? $cancelCount / $acceptedCount : 0.0;
            $disciplineScore = max(0.0, 1 - $cancelRate) * 10;

            $score = (int) round($activityScore + $satisfactionScore + $punctualityScore + $disciplineScore);

            $list[] = [
                'driver_id' => (int) $id,
                'name' => $driverInfo[$id]->name,
                'phone' => $driverInfo[$id]->phone,
                'score' => $score,
                'components' => [
                    'activite' => round($activityScore, 1),
                    'satisfaction' => round($satisfactionScore, 1),
                    'ponctualite' => round($punctualityScore, 1),
                    'discipline' => round($disciplineScore, 1),
                ],
                'kpi' => [
                    'completed_rides' => $completedCount,
                    'accepted_rides' => $acceptedCount,
                    'gross_volume' => (int) ($completed[$id]->gross ?? 0),
                    'earnings' => (int) ($completed[$id]->earnings ?? 0),
                    'cancellation_rate_pct' => $acceptedCount > 0 ? round($cancelRate * 100, 1) : 0,
                    'avg_pickup_seconds' => $avgPickup !== null ? (int) round($avgPickup) : null,
                    'rating' => $ratingAvg !== null ? round($ratingAvg, 2) : null,
                    'rating_count' => $ratingCnt,
                ],
            ];
        }

        usort($list, fn ($a, $b) => $b['score'] <=> $a['score']);
        $list = array_slice($list, 0, $limit);
        foreach ($list as $i => &$d) {
            $d['rank'] = $i + 1;
        }
        unset($d);

        return response()->json([
            'period_days' => $days,
            'weights' => ['activite' => 40, 'satisfaction' => 30, 'ponctualite' => 20, 'discipline' => 10],
            'currency' => 'XOF',
            'drivers' => $list,
        ]);
    }

    /**
     * §20.12 — Carte stratégique : chauffeurs (dispo/occupés), demandes en attente,
     * zones à forte demande / sous-desservies, temps moyen d'attente.
     */
    public function strategicMap(Request $request)
    {
        $now = Carbon::now();

        // Chauffeurs occupés = ayant une course active
        $busyIds = Ride::query()
            ->whereIn('status', ['accepted', 'arrived', 'pickup', 'ongoing'])
            ->whereNotNull('driver_id')
            ->pluck('driver_id')->unique()->flip();

        $onlineDrivers = User::query()
            ->where('role', 'driver')->where('is_active', true)->where('is_online', true)
            ->whereNotNull('last_lat')->whereNotNull('last_lng')
            ->get(['id', 'name', 'phone', 'last_lat', 'last_lng']);

        $drivers = $onlineDrivers->map(fn ($d) => [
            'id' => (int) $d->id,
            'name' => $d->name,
            'lat' => (float) $d->last_lat,
            'lng' => (float) $d->last_lng,
            'status' => isset($busyIds[$d->id]) ? 'busy' : 'available',
        ])->values();

        $availableCount = $drivers->where('status', 'available')->count();
        $busyCount = $drivers->where('status', 'busy')->count();

        // Demandes en attente (course requested avec coordonnées)
        $pending = Ride::query()
            ->where('status', 'requested')
            ->whereNotNull('pickup_lat')->whereNotNull('pickup_lng')
            ->get(['id', 'pickup_lat', 'pickup_lng', 'pickup_address', 'created_at'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'lat' => (float) $r->pickup_lat,
                'lng' => (float) $r->pickup_lng,
                'address' => $r->pickup_address,
                'waiting_minutes' => (int) $r->created_at->diffInMinutes($now),
            ])->values();

        // Temps moyen d'attribution aujourd'hui
        $avgAssignmentSec = (float) Ride::query()
            ->whereNotNull('accepted_at')
            ->whereBetween('accepted_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "AVG(strftime('%s', accepted_at) - strftime('%s', created_at)) as v" : "AVG(TIMESTAMPDIFF(SECOND, created_at, accepted_at)) as v")
            ->value('v');

        // Zones (grille ~1km, demandes des 6 dernières heures vs chauffeurs dispo)
        $since = $now->copy()->subHours(6);
        $demandCells = Ride::query()
            ->whereBetween('created_at', [$since, $now])
            ->whereNotNull('pickup_lat')->whereNotNull('pickup_lng')
            ->selectRaw('ROUND(pickup_lat, 2) as clat, ROUND(pickup_lng, 2) as clng, COUNT(*) as demand')
            ->groupBy('clat', 'clng')
            ->get();

        // Comptage des chauffeurs dispo par cellule
        $driverCells = [];
        foreach ($drivers as $d) {
            if ($d['status'] !== 'available') {
                continue;
            }
            $key = round($d['lat'], 2) . ',' . round($d['lng'], 2);
            $driverCells[$key] = ($driverCells[$key] ?? 0) + 1;
        }

        $zones = $demandCells->map(function ($c) use ($driverCells) {
            $key = (float) $c->clat . ',' . (float) $c->clng;
            $driversHere = $driverCells[$key] ?? 0;
            $demand = (int) $c->demand;
            return [
                'lat' => (float) $c->clat,
                'lng' => (float) $c->clng,
                'demand' => $demand,
                'available_drivers' => $driversHere,
                'high_demand' => $demand >= 5,
                'underserved' => $demand >= 3 && $driversHere === 0,
            ];
        })->sortByDesc('demand')->take(20)->values();

        return response()->json([
            'generated_at' => $now->toIso8601String(),
            'center' => ['lat' => 6.4969, 'lng' => 2.6283], // Porto-Novo
            'summary' => [
                'available_drivers' => $availableCount,
                'busy_drivers' => $busyCount,
                'pending_requests' => $pending->count(),
                'avg_wait_seconds' => $avgAssignmentSec > 0 ? (int) round($avgAssignmentSec) : null,
            ],
            'drivers' => $drivers,
            'pending_requests' => $pending,
            'zones' => $zones,
        ]);
    }

    public function driversDaily(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'driver_id' => ['required','integer','exists:users,id'],
            'tz' => ['nullable','string','max:64'],
        ]);

        $tz = $data['tz'] ?? 'UTC';
        $now = Carbon::now($tz);
        $fromLocal = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
        $toLocal = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : $now->copy()->endOfDay();
        $from = $fromLocal->copy()->setTimezone('UTC');
        $to = $toLocal->copy()->setTimezone('UTC');

        if ($from->gt($to)) {
            return response()->json(['message' => 'from must be before to'], 422);
        }

        $driverId = (int) $data['driver_id'];

        $cur = $from->copy()->startOfDay();
        $dateMap = [];
        while ($cur->lte($to)) {
            $dateMap[$cur->toDateString()] = [
                'date' => $cur->toDateString(),
                'total_rides' => 0,
                'completed_rides' => 0,
                'cancelled_rides' => 0,
                'gross_volume' => 0,
                'commission_total' => 0,
                'earnings_total' => 0,
                'currency' => 'XOF',
            ];
            $cur->addDay();
        }

        $completed = Ride::query()
            ->selectRaw('DATE(completed_at) as d, COUNT(*) as c, COALESCE(SUM(fare_amount),0) as gross, COALESCE(SUM(commission_amount),0) as comm, COALESCE(SUM(driver_earnings_amount),0) as earn')
            ->where('driver_id', $driverId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($completed as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['completed_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
            $dateMap[$d]['gross_volume'] = (int) $row->gross;
            $dateMap[$d]['commission_total'] = (int) $row->comm;
            $dateMap[$d]['earnings_total'] = (int) $row->earn;
        }

        $cancelled = Ride::query()
            ->selectRaw('DATE(cancelled_at) as d, COUNT(*) as c')
            ->where('driver_id', $driverId)
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($cancelled as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['cancelled_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
        }

        $out = array_values($dateMap);

        return response()->json([
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'timezone' => $tz,
            'driver_id' => $driverId,
            'data' => $out,
        ]);
    }

    public function driversDailyGlobal(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'tz' => ['nullable','string','max:64'],
        ]);

        $tz = $data['tz'] ?? 'UTC';
        $now = Carbon::now($tz);
        $fromLocal = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
        $toLocal = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : $now->copy()->endOfDay();
        $from = $fromLocal->copy()->setTimezone('UTC');
        $to = $toLocal->copy()->setTimezone('UTC');

        if ($from->gt($to)) {
            return response()->json(['message' => 'from must be before to'], 422);
        }

        $cur = $from->copy()->startOfDay();
        $dateMap = [];
        while ($cur->lte($to)) {
            $dateMap[$cur->toDateString()] = [
                'date' => $cur->toDateString(),
                'total_rides' => 0,
                'completed_rides' => 0,
                'cancelled_rides' => 0,
                'gross_volume' => 0,
                'commission_total' => 0,
                'earnings_total' => 0,
                'currency' => 'XOF',
            ];
            $cur->addDay();
        }

        $completed = Ride::query()
            ->selectRaw('DATE(completed_at) as d, COUNT(*) as c, COALESCE(SUM(fare_amount),0) as gross, COALESCE(SUM(commission_amount),0) as comm, COALESCE(SUM(driver_earnings_amount),0) as earn')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($completed as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['completed_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
            $dateMap[$d]['gross_volume'] = (int) $row->gross;
            $dateMap[$d]['commission_total'] = (int) $row->comm;
            $dateMap[$d]['earnings_total'] = (int) $row->earn;
        }

        $cancelled = Ride::query()
            ->selectRaw('DATE(cancelled_at) as d, COUNT(*) as c')
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($cancelled as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['cancelled_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
        }

        $out = array_values($dateMap);

        return response()->json([
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'timezone' => $tz,
            'data' => $out,
        ]);
    }

    public function topDriversDaily(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'tz' => ['nullable','string','max:64'],
            'limit' => ['nullable','integer','min:1','max:50'],
        ]);

        $limit = (int) ($data['limit'] ?? 10);
        $tz = $data['tz'] ?? 'UTC';
        $now = Carbon::now($tz);
        $fromLocal = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
        $toLocal = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : $now->copy()->endOfDay();
        $from = $fromLocal->copy()->setTimezone('UTC');
        $to = $toLocal->copy()->setTimezone('UTC');

        if ($from->gt($to)) {
            return response()->json(['message' => 'from must be before to'], 422);
        }

        $rows = Ride::query()
            ->leftJoin('users', 'users.id', '=', 'rides.driver_id')
            ->selectRaw('DATE(rides.completed_at) as d, rides.driver_id, users.name as driver_name, users.phone as driver_phone, COUNT(*) as completed, COALESCE(SUM(rides.fare_amount),0) as gross, COALESCE(SUM(rides.commission_amount),0) as comm, COALESCE(SUM(rides.driver_earnings_amount),0) as earn')
            ->where('rides.status', 'completed')
            ->whereBetween('rides.completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d', 'rides.driver_id', 'users.name', 'users.phone')
            ->get();

        $byDate = [];
        foreach ($rows as $r) {
            $d = (string) $r->d;
            if (!array_key_exists($d, $byDate)) {
                $byDate[$d] = [];
            }
            $byDate[$d][] = [
                'driver_id' => (int) $r->driver_id,
                'driver_name' => $r->driver_name,
                'driver_phone' => $r->driver_phone,
                'completed_rides' => (int) $r->completed,
                'gross_volume' => (int) $r->gross,
                'commission_total' => (int) $r->comm,
                'earnings_total' => (int) $r->earn,
                'currency' => 'XOF',
            ];
        }

        $cur = $from->copy()->startOfDay();
        $result = [];
        while ($cur->lte($to)) {
            $key = $cur->toDateString();
            $list = $byDate[$key] ?? [];
            usort($list, function ($a, $b) {
                if ($a['earnings_total'] === $b['earnings_total']) {
                    if ($a['completed_rides'] === $b['completed_rides']) {
                        return $a['driver_id'] <=> $b['driver_id'];
                    }
                    return $b['completed_rides'] <=> $a['completed_rides'];
                }
                return $b['earnings_total'] <=> $a['earnings_total'];
            });
            $result[] = [
                'date' => $key,
                'top' => array_slice($list, 0, $limit),
            ];
            $cur->addDay();
        }

        return response()->json([
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'timezone' => $tz,
            'limit' => $limit,
            'data' => $result,
        ]);
    }
}
