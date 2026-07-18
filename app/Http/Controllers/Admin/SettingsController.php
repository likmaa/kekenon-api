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

        return response()->json([
            'daily_tip' => $tip,
            'tic_line_unit_price' => $linePrice,
            'commission_platform' => 0,
            'commission_driver' => 100,
            'commission_maintenance' => 0,
            'commission_enabled' => false,
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

        if (isset($data['commission_platform']) || isset($data['commission_driver']) || isset($data['commission_maintenance'])) {
            $platform = (float) ($data['commission_platform'] ?? 0);
            $driver = (float) ($data['commission_driver'] ?? 100);
            $maintenance = (float) ($data['commission_maintenance'] ?? 0);
            if ($platform !== 0.0 || $driver !== 100.0 || $maintenance !== 0.0) {
                return response()->json([
                    'message' => 'Le modèle Kêkênon ne prélève plus de commission sur le tarif. Configurez les frais passager et le pack zem dans Tarification.',
                    'code' => 'COMMISSION_MODEL_DISABLED',
                ], 422);
            }
        }

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

        return response()->json([
            'message' => 'Paramètres mis à jour',
            'daily_tip' => $data['daily_tip'] ?? null,
            'tic_line_unit_price' => $data['tic_line_unit_price'] ?? null,
            'commission_platform' => 0,
            'commission_driver' => 100,
            'commission_maintenance' => 0,
            'commission_enabled' => false,
        ]);
    }
}
