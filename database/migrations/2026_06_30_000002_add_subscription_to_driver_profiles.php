<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedInteger('subscription_remaining_rides')->default(0)
                  ->after('status')
                  ->comment('Nombre de courses restantes autorisées pour le chauffeur');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('subscription_remaining_rides');
        });
    }
};
