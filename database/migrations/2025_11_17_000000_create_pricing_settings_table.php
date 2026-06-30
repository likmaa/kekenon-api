<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('base_fare')->default(500);
            $table->unsignedInteger('per_km')->default(150);
            $table->unsignedInteger('per_min')->default(50);
            $table->unsignedInteger('min_fare')->default(1000);
            $table->boolean('peak_hours_enabled')->default(false);
            $table->decimal('peak_hours_multiplier', 5, 2)->default(1.0);
            $table->time('peak_hours_start_time')->default('17:00:00');
            $table->time('peak_hours_end_time')->default('20:00:00');
            $table->json('zones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};
