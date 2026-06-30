<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ajouter les champs de bidding à la table rides
        Schema::table('rides', function (Blueprint $table) {
            $table->string('pricing_mode')->default('fixed')->after('service_type')
                  ->comment('fixed = prix estimé Kêkênon | negotiable = prix à négocier');
            $table->unsignedInteger('negotiated_fare')->nullable()->after('pricing_mode')
                  ->comment('Prix final accepté lors d\'une négociation (XOF)');
            $table->unsignedBigInteger('bid_accepted_driver_id')->nullable()->after('negotiated_fare')
                  ->comment('ID du chauffeur dont l\'offre a été acceptée');
        });

        // 2. Créer la table ride_bids pour les contre-propositions de négociation
        Schema::create('ride_bids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ride_id');
            $table->unsignedBigInteger('sender_id')->comment('user_id de l\'émetteur du prix (passager ou chauffeur)');
            $table->unsignedInteger('proposed_fare')->comment('Tarif proposé (XOF)');
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamps();

            $table->foreign('ride_id')->references('id')->on('rides')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['ride_id', 'status']);
            $table->index(['sender_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'negotiated_fare', 'bid_accepted_driver_id']);
        });
        Schema::dropIfExists('ride_bids');
    }
};
