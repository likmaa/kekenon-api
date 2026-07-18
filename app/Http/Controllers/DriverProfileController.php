<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\EconomicModelService;

class DriverProfileController extends Controller
{
    public function __construct(private EconomicModelService $economicModel)
    {
    }

    /**
     * Display the driver's profile.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $profile = $user->driverProfile;
        $businessModel = $this->economicModel->get();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'role' => $user->role,
                'photo' => $user->photo,
                'is_online' => (bool) $user->is_online,
            ],
            'profile' => $profile ? [
                'status' => $profile->status,
                'vehicle_number' => $profile->vehicle_number,
                'license_number' => $profile->license_number,
                'vehicle_make' => $profile->vehicle_make,
                'vehicle_model' => $profile->vehicle_model,
                'vehicle_year' => $profile->vehicle_year,
                'vehicle_color' => $profile->vehicle_color,
                'license_plate' => $profile->license_plate,
                'vehicle_type' => $profile->vehicle_type,
                'photo' => $profile->photo,
                'documents' => $profile->documents,
                'contract_accepted_at' => $profile->contract_accepted_at,
                'subscription_remaining_rides' => (int) $profile->subscription_remaining_rides,
                'subscription_pack_price' => $businessModel['driver_pack_price'],
                'subscription_pack_rides' => $businessModel['driver_pack_rides'],
                'driver_ride_share_pct' => $businessModel['driver_ride_share_pct'],
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
            ] : null,
        ]);
    }

    /**
     * Store or update the driver's profile.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'vehicle_number' => ['nullable', 'string', 'max:64'],
            'license_number' => ['required', 'string', 'max:64'],
            'vehicle_make' => ['nullable', 'string', 'max:100'],
            'vehicle_model' => ['nullable', 'string', 'max:100'],
            'vehicle_year' => ['nullable', 'string', 'max:4'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'license_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_type' => ['nullable', 'string', 'in:sedan,suv,van,compact'],
            'photo' => $request->hasFile('photo')
                ? ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120']
                : ['nullable', 'string', 'max:500'],
            'documents' => ['nullable', 'array'],
        ]);

        $profile = DB::transaction(function () use ($user, $data, $request) {
            // Update User fields (legacy support)
            if ($request->hasFile('photo')) {
                $path = $request->file('photo')->store('profiles', 'public');
                $user->photo = $path; // Chemin relatif
            } elseif (!empty($data['photo'])) {
                $user->photo = $data['photo'];
            }
            $user->save();

            // Update or Create Driver Profile
            return DriverProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'vehicle_number' => $data['vehicle_number'] ?? null,
                    'license_number' => $data['license_number'],
                    'vehicle_make' => $data['vehicle_make'] ?? null,
                    'vehicle_model' => $data['vehicle_model'] ?? null,
                    'vehicle_year' => $data['vehicle_year'] ?? null,
                    'vehicle_color' => $data['vehicle_color'] ?? null,
                    'license_plate' => $data['license_plate'] ?? null,
                    'vehicle_type' => $data['vehicle_type'] ?? 'sedan',
                    'photo' => $data['photo'] ?? $user->photo,
                    'status' => 'pending',
                    'documents' => $data['documents'] ?? null,
                    'updated_at' => now(),
                ]
            );
        });

        return response()->json([
            'ok' => true,
            'message' => 'Driver profile submitted, waiting for admin approval.',
            'profile' => $profile,
        ], 201);
    }

    /**
     * Accept the driver contract.
     */
    public function acceptContract(Request $request)
    {
        $user = $request->user();

        $profile = DriverProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'contract_accepted_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'ok' => true,
            'user_id' => $user->id,
            'contract_accepted_at' => $profile->contract_accepted_at,
        ]);
    }

    /**
     * Upload or update one driver document (photo/image).
     * POST /api/driver/profile/documents
     */
    public function uploadDocument(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'document_key' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', 'string', 'in:valid,pending,expired,submitted,approved,rejected'],
            'expiry' => ['nullable', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $profile = DriverProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'pending']
        );

        $path = $request->file('file')->store('driver_documents', 'public');

        $documents = is_array($profile->documents) ? $profile->documents : [];
        $key = (string) $data['document_key'];

        $documents[$key] = [
            'name' => $data['name'] ?? $key,
            'status' => $data['status'] ?? 'pending',
            'expiry' => $data['expiry'] ?? null,
            'path' => $path,
            'updated_at' => now()->toISOString(),
        ];

        $profile->documents = $documents;
        $profile->status = $profile->status ?: 'pending';
        $profile->save();

        return response()->json([
            'ok' => true,
            'message' => 'Document enregistré avec succès.',
            'document_key' => $key,
            'document' => $documents[$key],
            'profile' => $profile->fresh(),
        ]);
    }
}
