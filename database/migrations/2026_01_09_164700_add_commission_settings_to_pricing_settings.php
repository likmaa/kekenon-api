<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            // Commission percentages (in whole numbers, e.g., 70 = 70%)
            $table->unsignedTinyInteger('platform_commission_pct')->default(70)->after('zones');
            $table->unsignedTinyInteger('driver_commission_pct')->default(20)->after('platform_commission_pct');
            $table->unsignedTinyInteger('maintenance_commission_pct')->default(10)->after('driver_commission_pct');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn(['platform_commission_pct', 'driver_commission_pct', 'maintenance_commission_pct']);
        });
    }
};
