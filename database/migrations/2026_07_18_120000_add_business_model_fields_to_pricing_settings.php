<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->unsignedInteger('passenger_app_fee')->default(25)->after('min_fare');
            $table->unsignedInteger('driver_pack_price')->default(500)->after('passenger_app_fee');
            $table->unsignedInteger('driver_pack_rides')->default(10)->after('driver_pack_price');
        });

        // L'ancien moteur utilisait une répartition en pourcentage. Le modèle Kêkênon
        // actuel laisse 100 % du prix de la course au zem et facture des frais séparés.
        DB::table('pricing_settings')->update([
            'platform_commission_pct' => 0,
            'driver_commission_pct' => 100,
            'maintenance_commission_pct' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'passenger_app_fee',
                'driver_pack_price',
                'driver_pack_rides',
            ]);
        });
    }
};
