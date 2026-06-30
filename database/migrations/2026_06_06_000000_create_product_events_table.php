<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §20.11 — Événements produit pour le funnel (APP_OPENED, RIDE_SEARCH_STARTED, ...).
 * Les étapes RIDE_REQUESTED → RIDE_COMPLETED sont déjà déductibles de la table `rides` ;
 * cette table capture le HAUT du funnel qui se produit dans l'app avant la création d'une course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event_type', 60); // app_opened, ride_search_started, ...
            $table->string('app_type', 20)->default('passenger'); // passenger | driver
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_type', 'created_at']);
            $table->index(['app_type', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_events');
    }
};
