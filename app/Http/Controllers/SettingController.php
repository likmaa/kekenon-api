<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\EconomicModelService;

class SettingController extends Controller
{
    public function __construct(private EconomicModelService $economicModel)
    {
    }

    public function getEconomicModel()
    {
        return response()->json($this->economicModel->get());
    }

    public function getDailyTip()
    {
        $tip = Setting::where('key', 'daily_tip')->value('value');
        
        // Fallback default tip if not set in DB
        if (!$tip) {
            $tip = "Les zones à forte demande sont actuellement à Cocody. Déplacez-vous pour plus de gains.";
        }

        return response()->json([
            'tip' => $tip
        ]);
    }
}
