<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Make some existing columns nullable if they aren't already
            if (Schema::hasColumn('users', 'name')) {
                $table->string('name')->nullable()->change();
            }
            if (Schema::hasColumn('users', 'email')) {
                // Modifier la colonne email pour la rendre nullable
                // Ne pas recréer l'index unique car il existe déjà
                $table->string('email')->nullable()->change();
            }
            if (Schema::hasColumn('users', 'password')) {
                $table->string('password')->nullable()->change();
            }

            // Ensure phone exists and is unique (previous migration adds it as nullable unique)
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->unique()->after('email');
            } else {
                // keep uniqueness; do not change nullability here to avoid platform-specific issues
            }

            // Role and profile-related fields
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('passenger')->after('phone');
            }
            if (!Schema::hasColumn('users', 'vehicle_number')) {
                $table->string('vehicle_number')->nullable()->after('role');
            }
            if (!Schema::hasColumn('users', 'license_number')) {
                $table->string('license_number')->nullable()->after('vehicle_number');
            }
            if (!Schema::hasColumn('users', 'photo')) {
                $table->string('photo')->nullable()->after('license_number');
            }
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('photo');
            }
            if (!Schema::hasColumn('users', 'is_online')) {
                $table->boolean('is_online')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('is_online');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert additions (do not revert email/name/password nullability to avoid data loss during down)
            if (Schema::hasColumn('users', 'phone_verified_at')) {
                $table->dropColumn('phone_verified_at');
            }
            if (Schema::hasColumn('users', 'is_online')) {
                $table->dropColumn('is_online');
            }
            if (Schema::hasColumn('users', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('users', 'photo')) {
                $table->dropColumn('photo');
            }
            if (Schema::hasColumn('users', 'license_number')) {
                $table->dropColumn('license_number');
            }
            if (Schema::hasColumn('users', 'vehicle_number')) {
                $table->dropColumn('vehicle_number');
            }
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
            // do not drop phone here because an earlier migration manages it
        });
    }
};
