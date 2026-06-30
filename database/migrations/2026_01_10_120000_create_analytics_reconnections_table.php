<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analytics_reconnections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('ride_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('disconnected_at');
            $table->timestamp('reconnected_at');
            $table->integer('duration_ms'); // Durée en millisecondes
            $table->boolean('data_synced')->default(false);
            $table->integer('sync_duration_ms')->nullable(); // Temps de synchronisation en millisecondes
            $table->string('app_type', 20)->default('driver'); // 'driver' ou 'passenger'
            $table->timestamps();
            
            // Index pour les requêtes fréquentes
            $table->index(['user_id', 'created_at']);
            $table->index('ride_id');
            $table->index('app_type');
            $table->index('disconnected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_reconnections');
    }
};
