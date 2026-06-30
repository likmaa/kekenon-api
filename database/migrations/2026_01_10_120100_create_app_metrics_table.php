<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table pour stocker les métriques de performance des apps mobiles
     * (appels API, événements WebSocket, polling, etc.)
     */
    public function up(): void
    {
        Schema::create('app_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable(); // Optionnel, peut être anonyme
            $table->string('type', 50); // 'api_call', 'websocket_event', 'polling_triggered', 'network_change'
            $table->string('app_type', 20)->default('driver'); // 'driver' ou 'passenger'
            $table->json('data')->nullable(); // Données additionnelles (endpoint, event name, etc.)
            $table->timestamp('created_at');
            
            // Index pour les requêtes fréquentes
            $table->index(['type', 'created_at']);
            $table->index('app_type');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_metrics');
    }
};
