<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PassengerAddressController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user instanceof User || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $addresses = Address::where('user_id', $user->id)
            ->orderByDesc('is_favorite')
            ->orderBy('label')
            ->get();

        return response()->json($addresses);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user instanceof User || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'full_address' => ['required', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        $address = Address::create([
            'user_id' => $user->id,
            'label' => $data['label'],
            'full_address' => $data['full_address'],
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'type' => $data['type'] ?? null,
        ]);

        return response()->json($address, 201);
    }

    public function update(Request $request, int $id)
    {
        $user = Auth::user();
        if (!$user instanceof User || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $address = Address::where('user_id', $user->id)->findOrFail($id);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'full_address' => ['sometimes', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        $address->fill($data);
        $address->save();

        return response()->json($address);
    }

    public function destroy(Request $request, int $id)
    {
        $user = Auth::user();
        if (!$user instanceof User || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $address = Address::where('user_id', $user->id)->findOrFail($id);
        $address->delete();

        return response()->json(['message' => 'Adresse supprimée']);
    }
}
