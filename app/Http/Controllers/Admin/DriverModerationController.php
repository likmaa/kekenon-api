<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverModerationController extends Controller
{
    public function indexPending(Request $request)
    {
        $drivers = User::query()
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'pending')
            ->whereNotNull('driver_profiles.license_number')
            ->select(
                'users.id',
                'users.name',
                'users.phone',
                'users.role',
                'driver_profiles.status',
                'driver_profiles.vehicle_number',
                'driver_profiles.license_number'
            )
            ->orderByDesc('users.id')
            ->paginate(20);

        return response()->json($drivers);
    }

    public function indexApproved(Request $request)
    {
        $drivers = User::query()
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->select(
                'users.id',
                'users.name',
                'users.phone',
                'users.role',
                'driver_profiles.status',
                'driver_profiles.vehicle_number',
                'driver_profiles.license_number'
            )
            ->orderByDesc('users.id')
            ->paginate(50);

        return response()->json($drivers);
    }

    public function online(Request $request)
    {
        $query = User::query()
            ->where('users.role', 'driver')
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->whereNotNull('driver_profiles.contract_accepted_at')
            ->select(
                'users.id',
                'users.name',
                'users.phone',
                'users.email',
                'users.photo as user_photo',
                'users.is_online',
                'users.last_lat',
                'users.last_lng',
                'users.last_location_at',
                'driver_profiles.status',
                'driver_profiles.photo as profile_photo',
                'driver_profiles.vehicle_number',
                'driver_profiles.license_number',
                'driver_profiles.documents'
            )
            ->orderByDesc('users.id');

        // Optional filter: ?online=1 or ?online=0 to restrict results
        $online = $request->query('online');
        if ($online !== null && $online !== '') {
            if (in_array($online, ['1', 'true', 1, true], true)) {
                $query->where('users.is_online', true);
            } elseif (in_array($online, ['0', 'false', 0, false], true)) {
                $query->where('users.is_online', false);
            }
        }

        $drivers = $query->get()->map(function($d) {
            $photo = $d->profile_photo ?: $d->user_photo;
            if ($photo && !str_starts_with($photo, 'http')) {
                // Nettoyage au cas où le chemin commence déjà par storage ou /storage
                $path = ltrim($photo, '/');
                if (str_starts_with($path, 'storage/')) {
                    $path = substr($path, 8);
                }
                $photo = url('api/storage/' . $path);
            }
            $d->photo = $photo;
            return $d;
        });

        return response()->json($drivers);
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,approved,rejected'],
        ]);

        $user = User::where('id', $id)->firstOrFail();

        $result = DB::transaction(function () use ($user, $data) {
            $profile = DB::table('driver_profiles')->where('user_id', $user->id)->first();
            $previousStatus = $profile->status ?? null;

            $rejectPendingDriverCandidate = $data['status'] === 'rejected'
                && ($user->role ?? null) === 'driver'
                && $previousStatus === 'pending';

            if ($rejectPendingDriverCandidate) {
                $hasRides = DB::table('rides')
                    ->where(function ($q) use ($user) {
                        $q->where('driver_id', $user->id)->orWhere('rider_id', $user->id);
                    })
                    ->exists();

                if ($hasRides) {
                    // Cas rare : conserver l’intégrité référentielle, libérer le numéro (unicité).
                    $this->anonymizeRejectedDriverCandidate($user);
                    DB::table('driver_profiles')->updateOrInsert(
                        ['user_id' => $user->id],
                        ['status' => 'rejected', 'updated_at' => now()]
                    );

                    return ['deleted' => false, 'user_id' => $user->id, 'status' => 'rejected'];
                }

                $this->purgeDriverCandidateBeforeUserDelete($user->id);
                $user->delete();

                return ['deleted' => true, 'user_id' => $user->id, 'status' => 'rejected'];
            }

            DB::table('driver_profiles')->updateOrInsert(
                ['user_id' => $user->id],
                ['status' => $data['status']]
            );

            if ($data['status'] === 'approved' && $user->role !== 'driver') {
                $user->role = 'driver';
                $user->save();
            }

            return ['deleted' => false, 'user_id' => $user->id, 'status' => $data['status']];
        });

        return response()->json([
            'ok' => true,
            'user_id' => $result['user_id'],
            'status' => $result['status'],
            'deleted' => $result['deleted'],
        ]);
    }

    /**
     * Données sans contrainte FK vers users : à retirer avant suppression du User.
     */
    private function purgeDriverCandidateBeforeUserDelete(int $userId): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $userId)
            ->where('tokenable_type', User::class)
            ->delete();

        DB::table('ratings')->where(function ($q) use ($userId) {
            $q->where('passenger_id', $userId)->orWhere('driver_id', $userId);
        })->delete();

        DB::table('driver_rewards')->where('driver_id', $userId)->delete();

        DB::table('topup_requests')->where('user_id', $userId)->delete();
    }

    /**
     * Libère le numéro (champ unique) tout en gardant la ligne user si des courses existent.
     */
    private function anonymizeRejectedDriverCandidate(User $user): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', User::class)
            ->delete();

        DB::table('fcm_tokens')->where('user_id', $user->id)->delete();

        DB::table('wallet_transactions')
            ->whereIn('wallet_id', DB::table('wallets')->where('user_id', $user->id)->pluck('id'))
            ->delete();
        DB::table('wallets')->where('user_id', $user->id)->delete();

        DB::table('ratings')->where(function ($q) use ($user) {
            $q->where('passenger_id', $user->id)->orWhere('driver_id', $user->id);
        })->delete();

        DB::table('addresses')->where('user_id', $user->id)->delete();

        $user->update([
            'name' => 'Candidature refusée',
            'email' => null,
            'phone' => 'rejected_driver_' . $user->id . '_' . now()->timestamp,
            'photo' => null,
            'is_active' => false,
            'is_online' => false,
        ]);
    }

    public function location(int $id)
    {
        $driver = User::where('id', $id)->where('role', 'driver')->firstOrFail();

        return response()->json([
            'id' => $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'is_online' => (bool) ($driver->is_online ?? false),
            'last_lat' => $driver->last_lat,
            'last_lng' => $driver->last_lng,
            'last_location_at' => $driver->last_location_at,
        ]);
    }

    public function showProfile(int $id)
    {
        $user = User::findOrFail($id);

        $profile = DB::table('driver_profiles')->where('user_id', $user->id)->first();

        $userPhoto = $user->photo;
        if ($userPhoto && !str_starts_with($userPhoto, 'http')) {
            $userPhoto = url('api/storage/' . $userPhoto);
        }

        $profilePhoto = $profile->photo ?? null;
        if ($profilePhoto && !str_starts_with($profilePhoto, 'http')) {
            $profilePhoto = url('api/storage/' . $profilePhoto);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'role' => $user->role,
                'vehicle_number' => $user->vehicle_number,
                'license_number' => $user->license_number,
                'photo' => $userPhoto,
            ],
            'profile' => $profile ? [
                'status' => $profile->status,
                'vehicle_number' => $profile->vehicle_number,
                'license_number' => $profile->license_number,
                'photo' => $profilePhoto,
                'documents' => $profile->documents ? json_decode($profile->documents, true) : null,
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
            ] : null,
        ]);
    }

    public function forceOffline(int $id)
    {
        $driver = User::query()
            ->where('id', $id)
            ->where('role', 'driver')
            ->firstOrFail();

        $driver->is_online = false;
        $driver->save();

        return response()->json([
            'ok' => true,
            'user_id' => $driver->id,
            'is_online' => (bool) $driver->is_online,
        ]);
    }

    public function forceOnline(int $id)
    {
        $driver = User::query()
            ->where('id', $id)
            ->where('role', 'driver')
            ->firstOrFail();

        $driver->is_online = true;
        // Optionnel: on pourrait aussi mettre à jour last_location_at si besoin
        $driver->save();

        return response()->json([
            'ok' => true,
            'user_id' => $driver->id,
            'is_online' => (bool) $driver->is_online,
        ]);
    }
}
