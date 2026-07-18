<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Négociation verbale : un chauffeur prend la course négociable, appelle le
     * passager, ils s'accordent au téléphone. Le passager CONFIRME ensuite le
     * chauffeur — c'est cet horodatage qui active « Aller chercher mon client ».
     * Null tant que le passager n'a pas confirmé (courses fixes : ignoré).
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('negotiation_confirmed_at')->nullable()->after('negotiated_fare');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('negotiation_confirmed_at');
        });
    }
};
