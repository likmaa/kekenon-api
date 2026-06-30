<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class MetricsController extends Controller
{
    /**
     * Récupère les métriques de performance
     * Pour l'instant, retourne des données mockées
     * TODO: Implémenter la collecte réelle depuis les apps mobiles
     */
    public function index(Request $request)
    {
        $period = $request->query('period', '24h');
        
        // Calculer la date de début selon la période
        $now = Carbon::now();
        $from = match($period) {
            '24h' => $now->copy()->subHours(24),
            '7d' => $now->copy()->subDays(7),
            '30d' => $now->copy()->subDays(30),
            default => $now->copy()->subHours(24),
        };

        // Vérifier si la table existe
        $tableExists = DB::getSchemaBuilder()->hasTable('app_metrics');
        
        if (!$tableExists) {
            // Retourner des données vides si la table n'existe pas encore
            return response()->json([
                'total' => 0,
                'apiCalls' => 0,
                'websocketEvents' => 0,
                'pollingTriggers' => 0,
                'networkChanges' => 0,
                'reduction' => [
                    'pollingVsWebsocket' => '0',
                ],
                'period' => [
                    'from' => $from->toIso8601String(),
                    'to' => $now->toIso8601String(),
                ],
            ]);
        }

        // Récupérer les événements depuis la table app_metrics
        $events = DB::table('app_metrics')
            ->whereBetween('created_at', [$from, $now])
            ->get();

        $metrics = [
            'total' => $events->count(),
            'apiCalls' => $events->where('type', 'api_call')->count(),
            'websocketEvents' => $events->where('type', 'websocket_event')->count(),
            'pollingTriggers' => $events->where('type', 'polling_triggered')->count(),
            'networkChanges' => $events->where('type', 'network_change')->count(),
            'reduction' => [
                'pollingVsWebsocket' => '0',
            ],
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $now->toIso8601String(),
            ],
        ];

        // Calculer le pourcentage de réduction (WebSocket vs Polling)
        $totalComms = $metrics['websocketEvents'] + $metrics['pollingTriggers'];
        if ($totalComms > 0) {
            $metrics['reduction']['pollingVsWebsocket'] = number_format(
                ($metrics['websocketEvents'] / $totalComms) * 100,
                1
            );
        }

        return response()->json($metrics);
    }
}
