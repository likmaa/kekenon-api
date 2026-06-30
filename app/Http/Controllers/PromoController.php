<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PromoCode;
use Illuminate\Support\Facades\Auth;

class PromoController extends Controller
{
    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $codeStr = strtoupper(trim((string) $request->input('code')));
        $promo = PromoCode::where('code', $codeStr)->first();

        if (!$promo) {
            return response()->json(['error' => 'Code promo invalide.'], 404);
        }

        if (!$promo->isValid()) {
            return response()->json(['error' => 'Ce code promo est expiré, inactif, ou a atteint sa limite d\'utilisation.'], 422);
        }

        // Optionnel : on pourrait vérifier si l'utilisateur l'a déjà utilisé en regardant sa table rides
        $user = Auth::user();
        if ($user) {
            $alreadyUsed = $user->rides()->where('promo_code_id', $promo->id)->exists();
            if ($alreadyUsed) {
                return response()->json(['error' => 'Vous avez déjà utilisé ce code promo.'], 422);
            }
        }

        return response()->json([
            'valid' => true,
            'id' => $promo->id,
            'code' => $promo->code,
            'type' => $promo->type,
            'value' => $promo->value,
        ]);
    }
}
