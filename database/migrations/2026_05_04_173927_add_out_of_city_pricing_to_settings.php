<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->boolean('out_of_city_enabled')->default(false);
            $table->decimal('out_of_city_multiplier', 5, 2)->default(1.50);
            $table->integer('out_of_city_min_fare')->nullable();
            $table->decimal('inner_city_lat', 10, 8)->default(6.4969);
            $table->decimal('inner_city_lng', 11, 8)->default(2.6289);
            $table->integer('inner_city_radius_km')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'out_of_city_enabled',
                'out_of_city_multiplier',
                'out_of_city_min_fare',
                'inner_city_lat',
                'inner_city_lng',
                'inner_city_radius_km'
            ]);
        });
    }
};
