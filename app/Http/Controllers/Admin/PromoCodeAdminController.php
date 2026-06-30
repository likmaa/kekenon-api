<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PromoCode;
use Illuminate\Validation\Rule;

class PromoCodeAdminController extends Controller
{
    public function index()
    {
        $promos = PromoCode::orderByDesc('id')->get();
        return response()->json($promos);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'unique:promo_codes,code'],
            'type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'value' => ['required', 'numeric', 'min:0'],
            'city' => ['nullable', 'string'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);

        $validated['code'] = strtoupper(trim((string) $validated['code']));
        
        $promo = PromoCode::create($validated);

        return response()->json($promo, 201);
    }

    public function update(Request $request, int $id)
    {
        $promo = PromoCode::findOrFail($id);

        $validated = $request->validate([
            'code' => ['required', 'string', Rule::unique('promo_codes')->ignore($promo->id)],
            'type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'value' => ['required', 'numeric', 'min:0'],
            'city' => ['nullable', 'string'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);

        $validated['code'] = strtoupper(trim((string) $validated['code']));
        
        $promo->update($validated);

        return response()->json($promo);
    }

    public function destroy(int $id)
    {
        $promo = PromoCode::findOrFail($id);
        $promo->delete();

        return response()->json(['message' => 'Promo code deleted successfully']);
    }
}
