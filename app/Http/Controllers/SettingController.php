<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class SettingController extends Controller
{
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
