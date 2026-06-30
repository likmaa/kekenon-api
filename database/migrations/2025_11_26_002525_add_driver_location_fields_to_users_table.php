<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Ajout des coordonnées de localisation du chauffeur
            // Vérifier si les colonnes existent déjà avant de les ajouter
            if (!Schema::hasColumn('users', 'last_lat')) {
                $table->decimal('last_lat', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('users', 'last_lng')) {
                $table->decimal('last_lng', 10, 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_lat', 'last_lng']);
        });
    }
};
