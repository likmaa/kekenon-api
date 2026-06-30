<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->decimal('weather_multiplier', 5, 2)->default(1.0)->after('peak_hours_end_time');
            $table->boolean('weather_mode_enabled')->default(false)->after('weather_multiplier');
            $table->decimal('night_multiplier', 5, 2)->default(1.0)->after('weather_mode_enabled');
            $table->time('night_start_time')->default('22:00:00')->after('night_multiplier');
            $table->time('night_end_time')->default('06:00:00')->after('night_start_time');
            $table->unsignedInteger('stop_rate_per_min')->default(5)->after('night_end_time'); // 5 FCFA/min = 50 FCFA/10min
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedInteger('total_stop_duration_s')->default(0)->after('duration_s');
            $table->timestamp('stop_started_at')->nullable()->after('total_stop_duration_s');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'weather_multiplier',
                'weather_mode_enabled',
                'night_multiplier',
                'night_start_time',
                'night_end_time',
                'stop_rate_per_min'
            ]);
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['total_stop_duration_s', 'stop_started_at']);
        });
    }
};
