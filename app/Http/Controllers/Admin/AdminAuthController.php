<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'min:8', 'max:20'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            return response()->json(['message' => 'Email ou téléphone requis'], 422);
        }

        $query = User::query()->whereIn('role', ['admin', 'developer']);
        if (!empty($data['email'])) {
            $query->where('email', $data['email']);
        } else {
            $query->where('phone', $data['phone']);
        }

        $user = $query->first();
        if (!$user || !Hash::check($data['password'], (string) $user->password)) {
            return response()->json(['message' => 'Identifiants invalides'], 401);
        }

        $token = $user->createToken('admin')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'photo' => $user->photo,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke only the current access token
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        $u = $request->user();
        return response()->json([
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'photo' => $u->photo,
        ]);
    }
}
