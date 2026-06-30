<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductEvent;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    /**
     * §20.11 — Ingestion d'un événement produit (app_opened, ride_search_started, ...).
     * Appelé par les apps. user_id déduit du token si présent.
     */
    public function trackEvent(Request $request)
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:60'],
            'app_type' => ['nullable', 'string', 'in:passenger,driver'],
            'meta' => ['nullable', 'array'],
        ]);

        try {
            ProductEvent::create([
                'user_id' => optional($request->user())->id,
                'event_type' => $data['event_type'],
                'app_type' => $data['app_type'] ?? 'passenger',
                'meta' => $data['meta'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Non bloquant : l'analytics ne doit jamais casser l'app
            return response()->json(['status' => 'skipped'], 202);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * §20.11 — Funnel produit + DAU/WAU/MAU + taux de conversion / abandon.
     * Haut du funnel (app_opened, ride_search_started) = product_events ;
     * bas du funnel (requested → completed) = table rides.
     * ?days=30 par défaut.
     */
    public function funnel(Request $request)
    {
        $days = max(1, min((int) $request->input('days', 30), 365));
        $now = Carbon::now();
        $start = $now->copy()->subDays($days - 1)->startOfDay();
        $end = $now->copy()->endOfDay();

        $hasEvents = DB::getSchemaBuilder()->hasTable('product_events');
        $countEvent = function (string $type) use ($hasEvents, $start, $end) {
            if (!$hasEvents) {
                return null; // pas encore instrumenté côté app
            }
            return (int) ProductEvent::where('event_type', $type)
                ->whereBetween('created_at', [$start, $end])->count();
        };

        $appOpened = $countEvent('app_opened');
        $searchStarted = $countEvent('ride_search_started');

        $requested = (int) Ride::whereBetween('created_at', [$start, $end])->count();
        $assigned = (int) Ride::whereNotNull('accepted_at')->whereBetween('accepted_at', [$start, $end])->count();
        $started = (int) Ride::whereNotNull('started_at')->whereBetween('started_at', [$start, $end])->count();
        $completed = (int) Ride::where('status', 'completed')->whereBetween('completed_at', [$start, $end])->count();

        $steps = [
            ['key' => 'app_opened', 'label' => 'Ouverture app', 'count' => $appOpened, 'tracked' => $hasEvents],
            ['key' => 'ride_search_started', 'label' => 'Recherche démarrée', 'count' => $searchStarted, 'tracked' => $hasEvents],
            ['key' => 'ride_requested', 'label' => 'Course demandée', 'count' => $requested, 'tracked' => true],
            ['key' => 'driver_assigned', 'label' => 'Chauffeur attribué', 'count' => $assigned, 'tracked' => true],
            ['key' => 'ride_started', 'label' => 'Course démarrée', 'count' => $started, 'tracked' => true],
            ['key' => 'ride_completed', 'label' => 'Course terminée', 'count' => $completed, 'tracked' => true],
        ];

        // Taux de conversion d'étape à étape (sur les étapes réellement suivies et non nulles)
        for ($i = 0; $i < count($steps); $i++) {
            $prev = $i > 0 ? $steps[$i - 1]['count'] : null;
            $cur = $steps[$i]['count'];
            $steps[$i]['step_conversion_pct'] = ($prev && $prev > 0 && $cur !== null)
                ? round(($cur / $prev) * 100, 1) : null;
        }

        // Conversion globale demande → complétion
        $globalConversion = $requested > 0 ? round(($completed / $requested) * 100, 1) : null;
        $abandonment = $globalConversion !== null ? round(100 - $globalConversion, 1) : null;

        // DAU / WAU / MAU — utilisateurs actifs (app_opened si dispo, sinon passagers ayant commandé)
        $distinctActive = function ($from) use ($hasEvents, $now) {
            if ($hasEvents) {
                $c = (int) ProductEvent::where('event_type', 'app_opened')
                    ->whereBetween('created_at', [$from, $now])
                    ->distinct('user_id')->count('user_id');
                if ($c > 0) {
                    return $c;
                }
            }
            return (int) Ride::whereBetween('created_at', [$from, $now])
                ->whereNotNull('rider_id')->distinct('rider_id')->count('rider_id');
        };

        return response()->json([
            'period_days' => $days,
            'events_tracked' => $hasEvents,
            'steps' => $steps,
            'global_conversion_pct' => $globalConversion,
            'abandonment_pct' => $abandonment,
            'dau' => $distinctActive($now->copy()->startOfDay()),
            'wau' => $distinctActive($now->copy()->subDays(7)),
            'mau' => $distinctActive($now->copy()->subDays(30)),
        ]);
    }

    /**
     * Récupère les statistiques de reconnexion
     */
    public function reconnections(Request $request)
    {
        $period = $request->query('period', '7d');
        
        // Calculer la date de début selon la période
        $now = Carbon::now();
        $from = match($period) {
            '24h' => $now->copy()->subHours(24),
            '7d' => $now->copy()->subDays(7),
            '30d' => $now->copy()->subDays(30),
            default => $now->copy()->subDays(7),
        };

        // Vérifier si la table existe
        $tableExists = DB::getSchemaBuilder()->hasTable('analytics_reconnections');
        
        if (!$tableExists) {
            // Retourner des données vides si la table n'existe pas encore
            return response()->json([
                'total' => 0,
                'averageDuration' => 0,
                'averageSyncDuration' => 0,
                'successRate' => 0,
                'byAppType' => [
                    'driver' => 0,
                    'passenger' => 0,
                ],
                'recentEvents' => [],
            ]);
        }

        // Récupérer les événements
        $events = DB::table('analytics_reconnections')
            ->whereBetween('created_at', [$from, $now])
            ->orderBy('created_at', 'desc')
            ->get();

        $total = $events->count();
        
        if ($total === 0) {
            return response()->json([
                'total' => 0,
                'averageDuration' => 0,
                'averageSyncDuration' => 0,
                'successRate' => 0,
                'byAppType' => [
                    'driver' => 0,
                    'passenger' => 0,
                ],
                'recentEvents' => [],
            ]);
        }

        // Calculer les statistiques
        $averageDuration = $events->avg('duration_ms') / 1000; // en secondes
        $syncDurations = $events->whereNotNull('sync_duration_ms')->pluck('sync_duration_ms');
        $averageSyncDuration = $syncDurations->count() > 0 
            ? $syncDurations->avg() 
            : 0;
        
        $successful = $events->where('data_synced', true)->count();
        $successRate = ($successful / $total) * 100;

        $byAppType = [
            'driver' => $events->where('app_type', 'driver')->count(),
            'passenger' => $events->where('app_type', 'passenger')->count(),
        ];

        // Événements récents (20 derniers)
        $recentEvents = $events->take(20)->map(function ($event) {
            return [
                'id' => $event->id,
                'user_id' => $event->user_id,
                'ride_id' => $event->ride_id,
                'disconnected_at' => $event->disconnected_at,
                'reconnected_at' => $event->reconnected_at,
                'duration_ms' => $event->duration_ms,
                'data_synced' => (bool) $event->data_synced,
                'sync_duration_ms' => $event->sync_duration_ms,
                'app_type' => $event->app_type,
                'created_at' => $event->created_at,
            ];
        })->values();

        return response()->json([
            'total' => $total,
            'averageDuration' => round($averageDuration, 2),
            'averageSyncDuration' => round($averageSyncDuration, 0),
            'successRate' => round($successRate, 2),
            'byAppType' => $byAppType,
            'recentEvents' => $recentEvents,
        ]);
    }

    /**
     * Reçoit un événement de reconnexion depuis les apps mobiles
     */
    public function trackReconnection(Request $request)
    {
        $validated = $request->validate([
            'rideId' => 'required',
            'disconnectedAt' => 'required|integer',
            'reconnectedAt' => 'required|integer',
            'duration' => 'required|integer',
            'dataSynced' => 'required|boolean',
            'syncDuration' => 'nullable|integer',
        ]);

        // Vérifier si la table existe (doit être créée via migration)
        if (!DB::getSchemaBuilder()->hasTable('analytics_reconnections')) {
            return response()->json([
                'message' => 'Table analytics_reconnections n\'existe pas. Veuillez exécuter les migrations.',
            ], 500);
        }

        DB::table('analytics_reconnections')->insert([
            'user_id' => $request->user()->id,
            'ride_id' => $validated['rideId'],
            'disconnected_at' => Carbon::createFromTimestampMs($validated['disconnectedAt']),
            'reconnected_at' => Carbon::createFromTimestampMs($validated['reconnectedAt']),
            'duration_ms' => $validated['duration'],
            'data_synced' => $validated['dataSynced'],
            'sync_duration_ms' => $validated['syncDuration'] ?? null,
            'app_type' => $request->header('X-App-Type', 'driver'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
