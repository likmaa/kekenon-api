<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminAndDeveloperSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = env('ADMIN_SEED_PASSWORD');
        $devPassword = env('DEV_SEED_PASSWORD');

        if (empty($adminPassword) || empty($devPassword)) {
            throw new \RuntimeException(
                'ADMIN_SEED_PASSWORD and DEV_SEED_PASSWORD must be set in .env before running this seeder.'
            );
        }

        // Admin user
        $admin = User::updateOrCreate(
            ['phone' => '+10000000001'],
            [
                'name' => 'Admin User',
                'email' => 'admin@kekenon.com',
                'password' => Hash::make($adminPassword),
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );
        $admin->forceFill(['role' => 'admin'])->save();

        // Developer user
        $dev = User::updateOrCreate(
            ['phone' => '+10000000002'],
            [
                'name' => 'Developer User',
                'email' => 'dev@kekenon.com',
                'password' => Hash::make($devPassword),
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );
        $dev->forceFill(['role' => 'developer'])->save();
    }
}
