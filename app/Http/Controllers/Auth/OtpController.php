<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KyaSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\Phone;

class OtpController extends Controller
{
    protected $kyaSms;

    public function __construct(KyaSmsService $kyaSms)
    {
        $this->kyaSms = $kyaSms;
    }

    public function requestOtp(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20'],
            'force_new' => ['sometimes', 'boolean'], // Option pour forcer l'envoi d'un nouveau code
        ]);

        $phone = Phone::normalize($data['phone']); // ex: +229XXXXXXXX
        $forceNew = $data['force_new'] ?? false;

        $review = $this->reviewLoginConfig();
        if ($review && $phone === $review['phone']) {
            return response()->json([
                'status' => 'otp_sent',
                'message' => 'OTP envoyé (compte revue App Store / Play Console).',
                'otp_key' => $review['otp_key'],
            ]);
        }


        try {
            // Pour l'application driver, on force TOUJOURS l'envoi d'OTP
            // même si le numéro est déjà vérifié, pour garantir le flux OTP complet
            // On ne saute jamais l'OTP pour l'app driver

            // En production, déléguer complètement l'OTP à Kya SMS
            $providerResponse = $this->kyaSms->sendOtp($phone, $forceNew);

            // Gérer le cas où un OTP existe déjà (pas d'erreur, juste info)
            $status = ($providerResponse['reason'] ?? '') === 'already_exists'
                ? 'otp_exists'
                : 'otp_sent';

            $message = $status === 'otp_exists'
                ? 'Un code OTP est déjà en cours. Vérifiez vos SMS.'
                : 'OTP envoyé par SMS via KYA SMS.';

            return response()->json([
                'status' => $status,
                'message' => $message,
                'provider' => $providerResponse,
                // On renvoie explicitement la clé OTP au client pour vérification ultérieure
                'otp_key' => $providerResponse['key'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('OTP request failed', [
                'phone' => $phone ?? null,
                'force_new' => (bool) ($forceNew ?? false),
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => "Service OTP temporairement indisponible. Réessayez plus tard.",
            ], 503);
        }

    }


    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string',
            'code' => 'required|digits:6',
            'otp_key' => 'required|string',
            'role' => 'sometimes|string|in:passenger,driver',
        ]);

        $phone = Phone::normalize($data['phone']);

        $review = $this->reviewLoginConfig();
        $isReviewBypass = $review
            && $phone === $review['phone']
            && (string) $data['code'] === $review['otp']
            && (string) $data['otp_key'] === $review['otp_key'];

        if ($isReviewBypass) {
            $verifyResponse = ['reason' => 'success', 'status' => 200, 'msg' => 'checked'];
        } else {
            // Vérifier l'OTP auprès de KYA SMS en utilisant la clé retournée par /otp/create
            $verifyResponse = $this->kyaSms->verifyOtp($data['otp_key'], $data['code']);
        }

        // Doc KYA: { "reason": "success", "status": 200, "msg": "checked" }
        $reason = $verifyResponse['reason'] ?? null;
        $statusCode = $verifyResponse['status'] ?? null; // 200, 100, 101, 102, 103...

        if ($reason !== 'success') {
            // Mapper les principaux codes OTP de la doc KYA
            $friendlyMessage = 'Vérification OTP échouée.';
            if ($statusCode === 100) {
                $friendlyMessage = 'Clé OTP invalide. Veuillez redemander un nouveau code.';
            } elseif ($statusCode === 101) {
                $friendlyMessage = 'Nombre maximum de tentatives atteint. Veuillez redemander un nouveau code.';
            } elseif ($statusCode === 102) {
                $friendlyMessage = 'Code OTP incorrect. Veuillez vérifier le code saisi.';
            } elseif ($statusCode === 103) {
                $friendlyMessage = 'Code OTP expiré. Veuillez redemander un nouveau code.';
            }

            return response()->json([
                'status' => 'error',
                'message' => $friendlyMessage,
                'provider' => $verifyResponse,
            ], 422);
        }

        // OTP valide côté provider : on peut créer / mettre à jour l'utilisateur et le connecter
        $user = User::where('phone', $phone)->first();
        $isNewUser = false;
        $requestedRole = $data['role'] ?? 'passenger';

        if (!$user) {
            $isNewUser = true;
            $user = User::create([
                'name' => $phone,
                'email' => $phone . '@example.local',
                'password' => Hash::make(bin2hex(random_bytes(8))),
                'phone' => $phone,
                'is_active' => true,
                'phone_verified_at' => now(),
            ]);
            $user->forceFill(['role' => $requestedRole])->save();
        } else {
            // Identité Unique : on ne peut pas utiliser le même numéro pour deux rôles différents
            if ($user->role !== $requestedRole) {
                $roleLabel = $user->role === 'driver' ? 'chauffeur' : 'passager';
                return response()->json([
                    'status' => 'error',
                    'message' => "Ce numéro est déjà enregistré en tant que {$roleLabel}. Vous ne pouvez pas l'utiliser pour un autre rôle.",
                ], 422);
            }

            if (is_null($user->phone_verified_at)) {
                $user->phone_verified_at = now();
                $user->save();
            }
            if ($user->is_active === null) {
                $user->is_active = true;
                $user->save();
            }
        }

        // Si role='driver' est demandé, créer un profil driver avec status='pending'
        if ($requestedRole === 'driver') {
            $profileExists = DB::table('driver_profiles')->where('user_id', $user->id)->exists();

            if (!$profileExists) {
                // Créer un profil driver avec status='pending'
                DB::table('driver_profiles')->insert([
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Session unique : déconnexion automatique des anciens appareils
        $user->tokens()->delete();

        // Générer un token Sanctum pour la connexion mobile
        $token = $user->createToken('mobile')->plainTextToken;

        // Nettoyer l'OTP utilisé (utiliser la bonne clé de cache)
        cache()->forget('kya_otp_key_' . $phone);

        return response()->json([
            'status' => 'success',
            'message' => 'OTP validé.',
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


    public function me()
    {
        return response()->json(Auth::user());
    }

    public function logout(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        DB::transaction(function () use ($user, $request) {
            // Révoquer le token Sanctum courant.
            $user->currentAccessToken()?->delete();

            // Forcer hors-ligne le chauffeur lors de la déconnexion.
            if ($user->isDriver()) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'is_online' => false,
                        'updated_at' => now(),
                    ]);
            }

            // Nettoyage FCM : token device courant si fourni, sinon purge de sécurité.
            $fcmToken = trim((string) $request->input('fcm_token', ''));
            if ($fcmToken !== '') {
                DB::table('fcm_tokens')
                    ->where('user_id', $user->id)
                    ->where('token', $fcmToken)
                    ->delete();
            } else {
                DB::table('fcm_tokens')->where('user_id', $user->id)->delete();
            }
        });

        return response()->json([
            'message' => 'Déconnexion réussie',
            'driver_online' => $user->isDriver() ? false : null,
        ]);
    }


    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
            DB::table('fcm_tokens')->where('user_id', $user->id)->delete();
            DB::table('wallet_transactions')
                ->whereIn('wallet_id', DB::table('wallets')->where('user_id', $user->id)->pluck('id'))
                ->delete();
            DB::table('wallets')->where('user_id', $user->id)->delete();
            DB::table('ratings')->where(function ($q) use ($user) {
                $q->where('passenger_id', $user->id)->orWhere('driver_id', $user->id);
            })->delete();
            // Table réelle : `addresses` (PassengerAddressController / migration 2025_11_16_210000)
            DB::table('addresses')->where('user_id', $user->id)->delete();

            $user->update([
                'name' => 'Compte supprimé',
                'email' => null,
                'phone' => 'deleted_' . $user->id . '_' . now()->timestamp,
                'photo' => null,
                'is_active' => false,
            ]);
        });

        return response()->json(['message' => 'Compte supprimé avec succès.']);
    }

    public function updateProfile(Request $request)
    {
        try {
            /** @var \App\Models\User $user */
            $user = $request->user();

            $data = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'password' => ['nullable', 'string', 'min:8'],
                'photo' => $request->hasFile('photo')
                    ? ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120']
                    : ['nullable', 'string', 'max:500'],
            ]);

            if (isset($data['password']) && !empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            // Met à jour le nom complet si fourni
            if (array_key_exists('name', $data) && $data['name'] !== null && $data['name'] !== '') {
                $user->name = $data['name'];
            }

            if (array_key_exists('email', $data) && $data['email'] !== null) {
                $user->email = $data['email'];
            }

            // Optionnel : mise à jour du téléphone brut (on ne renormalise pas ici pour éviter de casser l'auth)
            if (array_key_exists('phone', $data) && $data['phone'] !== null && $data['phone'] !== '') {
                $user->phone = $data['phone'];
            }

            if ($request->hasFile('photo')) {
                $path = $request->file('photo')->store('profiles', 'public');
                $user->photo = $path; // On stocke juste le chemin relatif
            } elseif (array_key_exists('photo', $data) && $data['photo'] !== null && $data['photo'] !== '') {
                $user->photo = $data['photo'];
            }

            $user->save();

            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'photo' => $user->photo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur serveur lors de la mise à jour du profil.',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Compte OTP sans SMS pour revue App Store / Play Console.
     * null si désactivé (REVIEW_LOGIN_PHONE vide).
     *
     * @return array{phone: string, otp: string, otp_key: string}|null
     */
    private function reviewLoginConfig(): ?array
    {
        $raw = trim((string) config('services.review_login.phone', ''));
        if ($raw === '') {
            return null;
        }
        $phone = Phone::normalize($raw);
        $otp = trim((string) config('services.review_login.otp', ''));
        $otpKey = trim((string) config('services.review_login.otp_key', ''));
        if ($otp === '' || $otpKey === '') {
            return null;
        }

        return [
            'phone' => $phone,
            'otp' => $otp,
            'otp_key' => $otpKey,
        ];
    }
}
