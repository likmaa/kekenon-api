<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\DriverProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class QAUsersSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Passenger User (+22990000001)
        $passenger = User::updateOrCreate(
            ['phone' => '+22990000001'],
            [
                'name' => 'Passenger QA',
                'email' => 'passenger@kekenon.com',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );
        $passenger->forceFill(['role' => 'passenger'])->save();

        // Initialize Passenger Wallet
        $passengerWallet = DB::table('wallets')->where('user_id', $passenger->id)->first();
        if (!$passengerWallet) {
            DB::table('wallets')->insert([
                'user_id' => $passenger->id,
                'balance' => 5000,
                'currency' => 'XOF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('wallets')->where('user_id', $passenger->id)->update(['balance' => 5000]);
        }

        // 2. Zem Driver (+22990000002)
        $driver = User::updateOrCreate(
            ['phone' => '+22990000002'],
            [
                'name' => 'Zem Driver QA',
                'email' => 'zem@kekenon.com',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'is_online' => true,
                'phone_verified_at' => now(),
                // Porto-Novo coordinates by default
                'last_lat' => 6.4969,
                'last_lng' => 2.6283,
                'last_location_at' => now(),
            ]
        );
        $driver->forceFill(['role' => 'driver'])->save();

        // Initialize Driver Wallet
        $driverWallet = DB::table('wallets')->where('user_id', $driver->id)->first();
        if (!$driverWallet) {
            DB::table('wallets')->insert([
                'user_id' => $driver->id,
                'balance' => 2000,
                'currency' => 'XOF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('wallets')->where('user_id', $driver->id)->update(['balance' => 2000]);
        }

        // Create/Update Driver Profile with subscription and approved status
        DriverProfile::updateOrCreate(
            ['user_id' => $driver->id],
            [
                'status' => 'approved',
                'vehicle_number' => 'ZEM-1234',
                'license_number' => 'DT-9999', // Droit Taxi number
                'vehicle_make' => 'Haojue',
                'vehicle_model' => '115',
                'vehicle_year' => '2024',
                'vehicle_color' => 'Jaune Kêkênon',
                'license_plate' => '229-ZEM-1234',
                'vehicle_type' => '2wheel',
                'subscription_remaining_rides' => 10,
                'contract_accepted_at' => now(),
            ]
        );
    }
}
