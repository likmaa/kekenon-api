<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solde bonus (offert par Kêkênon, non retirable). Dépensé automatiquement
     * quand le solde principal ne suffit pas (ex. achat d'abonnement chauffeur).
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('bonus_balance')->default(0)->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('bonus_balance');
        });
    }
};
