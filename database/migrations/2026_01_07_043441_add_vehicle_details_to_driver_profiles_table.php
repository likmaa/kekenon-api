<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('vehicle_make')->nullable()->after('license_number');
            $table->string('vehicle_model')->nullable()->after('vehicle_make');
            $table->string('vehicle_year')->nullable()->after('vehicle_model');
            $table->string('vehicle_color')->nullable()->after('vehicle_year');
            $table->string('license_plate')->nullable()->after('vehicle_color');
            $table->string('vehicle_type')->default('sedan')->after('license_plate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'vehicle_make',
                'vehicle_model',
                'vehicle_year',
                'vehicle_color',
                'license_plate',
                'vehicle_type'
            ]);
        });
    }
};
