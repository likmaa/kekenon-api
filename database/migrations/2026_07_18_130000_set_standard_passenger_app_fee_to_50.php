<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Les 25 F étaient le tarif de lancement. Le tarif standard est désormais
        // de 50 F ; le panel permet de le ramener à 25 F pendant une promotion.
        DB::table('pricing_settings')
            ->where('passenger_app_fee', 25)
            ->update(['passenger_app_fee' => 50]);
    }

    public function down(): void
    {
        DB::table('pricing_settings')
            ->where('passenger_app_fee', 50)
            ->update(['passenger_app_fee' => 25]);
    }
};
