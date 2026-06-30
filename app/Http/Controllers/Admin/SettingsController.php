<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;

class SettingsController extends Controller
{
    public function index()
    {
        $tip = Setting::where('key', 'daily_tip')->value('value');
        $linePrice = Setting::where('key', 'tic_line_unit_price')->value('value') ?? 200;

        // Commission rates
        $platformCommission = Setting::where('key', 'commission_platform')->value('value') ?? 70;
        $driverCommission = Setting::where('key', 'commission_driver')->value('value') ?? 20;
        $maintenanceCommission = Setting::where('key', 'commission_maintenance')->value('value') ?? 10;

        return response()->json([
            'daily_tip' => $tip,
            'tic_line_unit_price' => $linePrice,
            'commission_platform' => (float) $platformCommission,
            'commission_driver' => (float) $driverCommission,
            'commission_maintenance' => (float) $maintenanceCommission,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'daily_tip' => 'nullable|string',
            'tic_line_unit_price' => 'nullable|numeric',
            'commission_platform' => 'nullable|numeric|min:0|max:100',
            'commission_driver' => 'nullable|numeric|min:0|max:100',
            'commission_maintenance' => 'nullable|numeric|min:0|max:100',
        ]);

        if (isset($data['daily_tip'])) {
            Setting::updateOrCreate(
                ['key' => 'daily_tip'],
                ['value' => $data['daily_tip']]
            );
        }

        if (isset($data['tic_line_unit_price'])) {
            Setting::updateOrCreate(
                ['key' => 'tic_line_unit_price'],
                ['value' => $data['tic_line_unit_price']]
            );
        }

        // Update commission rates
        if (isset($data['commission_platform'])) {
            Setting::updateOrCreate(
                ['key' => 'commission_platform'],
                ['value' => $data['commission_platform']]
            );
        }

        if (isset($data['commission_driver'])) {
            Setting::updateOrCreate(
                ['key' => 'commission_driver'],
                ['value' => $data['commission_driver']]
            );
        }

        if (isset($data['commission_maintenance'])) {
            Setting::updateOrCreate(
                ['key' => 'commission_maintenance'],
                ['value' => $data['commission_maintenance']]
            );
        }

        return response()->json([
            'message' => 'Paramètres mis à jour',
            'daily_tip' => $data['daily_tip'] ?? null,
            'tic_line_unit_price' => $data['tic_line_unit_price'] ?? null,
            'commission_platform' => $data['commission_platform'] ?? null,
            'commission_driver' => $data['commission_driver'] ?? null,
            'commission_maintenance' => $data['commission_maintenance'] ?? null,
        ]);
    }
}

