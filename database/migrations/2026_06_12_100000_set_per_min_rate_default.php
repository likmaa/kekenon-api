<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Active le tarif au temps (5 F/min après prise en charge).
 * La colonne per_min existait (défaut 50) mais n'était utilisée nulle part :
 * on aligne le défaut et les lignes existantes sur le tarif décidé (5 F/min).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->unsignedInteger('per_min')->default(5)->change();
        });

        // La valeur 50 était le défaut historique jamais appliqué au calcul —
        // on ne touche qu'aux lignes restées sur ce défaut.
        DB::table('pricing_settings')->where('per_min', 50)->update(['per_min' => 5]);
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->unsignedInteger('per_min')->default(50)->change();
        });
    }
};
