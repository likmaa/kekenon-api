<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distance d'approche : km parcourus par le chauffeur pour aller chercher le client
 * (de sa position à l'acceptation jusqu'au point de prise en charge).
 * Estimée par haversine × 1.3, comme la distance de course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedInteger('approach_distance_m')->nullable()->after('distance_m');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('approach_distance_m');
        });
    }
};
