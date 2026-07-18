<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Ride;
use App\Models\DriverReward;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    private const DIGITAL_METHODS = ['wallet', 'qr', 'card', 'mobile_money'];

    /**
     * §20.9 — KPI financiers : CA jour/semaine/mois, paiements espèces/digitaux/chauffeurs, dette globale.
     * Le détail des paiements porte sur la période (?from/?to, défaut : mois en cours).
     */
    public function overview(Request $request)
    {
        $now = Carbon::now();
        $rangeFrom = $request->query('from') ? Carbon::parse($request->query('from')) : $now->copy()->startOfMonth();
        $rangeTo = $request->query('to') ? Carbon::parse($request->query('to')) : $now->copy()->endOfDay();

        $completedSum = fn ($from, $to) => (int) Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->sum('fare_amount');

        $ca_today = $completedSum($now->copy()->startOfDay(), $now->copy()->endOfDay());
        $ca_week = $completedSum($now->copy()->startOfWeek(), $now->copy()->endOfWeek());
        $ca_month = $completedSum($now->copy()->startOfMonth(), $now->copy()->endOfMonth());

        $rangeRides = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$rangeFrom, $rangeTo]);

        $cashPayments = (int) (clone $rangeRides)
            ->where(function ($q) {
                $q->whereIn('payment_method', ['cash'])->orWhereNull('payment_method');
            })
            ->sum('fare_amount');

        $digitalPayments = (int) (clone $rangeRides)
            ->whereIn('payment_method', self::DIGITAL_METHODS)
            ->sum('fare_amount');

        $driverPayments = (int) (clone $rangeRides)->sum('driver_earnings_amount');

        return response()->json([
            'currency' => 'XOF',
            'range' => ['from' => $rangeFrom->toIso8601String(), 'to' => $rangeTo->toIso8601String()],
            'ca_today' => $ca_today,
            'ca_week' => $ca_week,
            'ca_month' => $ca_month,
            'cash_payments' => $cashPayments,
            'digital_payments' => $digitalPayments,
            'driver_payments' => $driverPayments,
        ]);
    }

    /**
     * §20.9 — Rapport financier agrégé (?granularity=day|week|month).
     */
    public function report(Request $request)
    {
        $granularity = $request->input('granularity', 'day');
        if (!in_array($granularity, ['day', 'week', 'month'], true)) {
            $granularity = 'day';
        }
        $monthsFr = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $now = Carbon::now();
        $buckets = [];

        if ($granularity === 'day') {
            for ($i = 29; $i >= 0; $i--) {
                $d = $now->copy()->subDays($i);
                $buckets[] = ['start' => $d->copy()->startOfDay(), 'end' => $d->copy()->endOfDay(), 'label' => $d->format('d/m/Y')];
            }
        } elseif ($granularity === 'week') {
            for ($i = 11; $i >= 0; $i--) {
                $d = $now->copy()->subWeeks($i);
                $buckets[] = ['start' => $d->copy()->startOfWeek(), 'end' => $d->copy()->endOfWeek(), 'label' => 'Semaine ' . $d->isoWeek() . ' (' . $d->format('Y') . ')'];
            }
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $d = $now->copy()->subMonths($i);
                $buckets[] = ['start' => $d->copy()->startOfMonth(), 'end' => $d->copy()->endOfMonth(), 'label' => $monthsFr[(int) $d->month] . ' ' . $d->format('Y')];
            }
        }

        $rows = [];
        foreach ($buckets as $b) {
            $q = Ride::query()->where('status', 'completed')->whereBetween('completed_at', [$b['start'], $b['end']]);
            $rows[] = [
                'label' => $b['label'],
                'rides_count' => (int) (clone $q)->count(),
                'gross_volume' => (int) (clone $q)->sum('fare_amount'),
                'commission' => (int) (clone $q)->sum('commission_amount'),
                'driver_earnings' => (int) (clone $q)->sum('driver_earnings_amount'),
                'cash' => (int) (clone $q)->where(function ($x) {
                    $x->whereIn('payment_method', ['cash'])->orWhereNull('payment_method');
                })->sum('fare_amount'),
                'digital' => (int) (clone $q)->whereIn('payment_method', self::DIGITAL_METHODS)->sum('fare_amount'),
            ];
        }

        return response()->json(['granularity' => $granularity, 'currency' => 'XOF', 'rows' => $rows]);
    }

    /**
     * §20.7 — Rentabilité par véhicule/chauffeur (données réelles).
     * NOTE : carburant et versement investisseur ne sont PAS dans le modèle de données.
     * Ces colonnes nécessitent un ajout de schéma + saisie manuelle (signalé via unavailable_fields).
     */
    public function fleetEconomics(Request $request)
    {
        $now = Carbon::now();
        $days = max(1, min((int) $request->input('days', 30), 365));
        $start = $now->copy()->subDays($days - 1)->startOfDay();
        $end = $now->copy()->endOfDay();

        $rows = DB::table('rides')
            ->join('users', 'users.id', '=', 'rides.driver_id')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->where('rides.status', 'completed')
            ->whereBetween('rides.completed_at', [$start, $end])
            ->groupBy('users.id', 'users.name', 'driver_profiles.license_plate', 'driver_profiles.vehicle_make', 'driver_profiles.vehicle_model', 'wallets.balance')
            ->selectRaw('users.id as driver_id, users.name as driver_name,
                driver_profiles.license_plate, driver_profiles.vehicle_make, driver_profiles.vehicle_model,
                wallets.balance as wallet_balance,
                COUNT(*) as rides_count,
                COALESCE(SUM(rides.fare_amount),0) as gross_revenue,
                COALESCE(SUM(rides.commission_amount),0) as platform_commission,
                COALESCE(SUM(rides.driver_earnings_amount),0) as driver_earnings,
                COALESCE(SUM(rides.distance_m),0) as distance_m')
            ->orderByDesc('gross_revenue')
            ->get()
            ->map(function ($r) {
                return [
                    'driver_id' => (int) $r->driver_id,
                    'driver_name' => $r->driver_name,
                    'license_plate' => $r->license_plate,
                    'vehicle' => trim((string) ($r->vehicle_make . ' ' . $r->vehicle_model)) ?: null,
                    'rides_count' => (int) $r->rides_count,
                    'gross_revenue' => (int) $r->gross_revenue,
                    'platform_commission' => (int) $r->platform_commission,
                    'driver_earnings' => (int) $r->driver_earnings,
                    'distance_km' => round(((int) $r->distance_m) / 1000, 1),
                ];
            })
            ->values();

        return response()->json([
            'period_days' => $days,
            'currency' => 'XOF',
            'rows' => $rows,
            'unavailable_fields' => ['fuel_cost', 'investor_payout', 'margin'],
        ]);
    }
    public function summary(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $now = now();

        $rangeFrom = $from ? $from : $now->copy()->startOfMonth()->toISOString();
        $rangeTo = $to ? $to : $now->toISOString();

        $ridesQuery = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$rangeFrom, $rangeTo]);

        $grossVolume = (int) $ridesQuery->sum('fare_amount');
        $netRevenue = (int) $ridesQuery->sum('commission_amount');
        $ridesCount = (int) $ridesQuery->count();

        $commissionRate = $grossVolume > 0 ? ($netRevenue / $grossVolume) : 0.0;

        $rewardsCount = (int) DriverReward::query()
            ->whereBetween('created_at', [$rangeFrom, $rangeTo])
            ->count();

        return response()->json([
            'range' => [
                'from' => $rangeFrom,
                'to' => $rangeTo,
            ],
            'gross_volume' => $grossVolume,
            'net_revenue' => $netRevenue,
            'commission_rate' => $commissionRate,
            'rides_count' => $ridesCount,
            'payouts_pending' => $rewardsCount,
        ]);
    }

    public function transactions(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $makeUnion = static function () {
            $ridesQuery = DB::table('rides')
                ->where('status', 'completed')
                ->select([
                    'id',
                    DB::raw("'ride_payment' as type"),
                    'fare_amount as amount',
                    'commission_amount as commission',
                    'driver_earnings_amount as payout',
                    'currency',
                    DB::raw("'succeeded' as status"),
                    'completed_at as created_at',
                ]);

            $rewardsQuery = DB::table('driver_rewards')
                ->select([
                    'id',
                    DB::raw("'driver_reward' as type"),
                    'amount',
                    DB::raw('NULL as commission'),
                    DB::raw('NULL as payout'),
                    DB::raw("'XOF' as currency"),
                    DB::raw("'succeeded' as status"),
                    'created_at',
                ]);

            return $ridesQuery->unionAll($rewardsQuery);
        };

        $total = (int) DB::query()
            ->fromSub($makeUnion(), 'tx_union')
            ->count();

        $items = DB::query()
            ->fromSub($makeUnion(), 'tx')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $out = [
                    'id' => (int) $arr['id'],
                    'type' => $arr['type'],
                    'amount' => $arr['type'] === 'ride_payment' ? (float) $arr['amount'] : (int) $arr['amount'],
                    'currency' => $arr['currency'] ?? 'XOF',
                    'status' => $arr['status'],
                    'created_at' => $arr['created_at'],
                ];
                if ($arr['type'] === 'ride_payment') {
                    $out['commission'] = isset($arr['commission']) ? (float) $arr['commission'] : null;
                    $out['payout'] = isset($arr['payout']) ? (float) $arr['payout'] : null;
                }

                return $out;
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);
    }
}
