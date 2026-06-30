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
        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Neighborhood name (e.g., "Ouando")
            $table->string('arrondissement')->nullable(); // e.g., "5e Arrondissement"
            $table->string('city')->default('Porto-Novo');
            $table->string('country')->default('Bénin');
            $table->decimal('lat', 10, 7)->nullable(); // Latitude
            $table->decimal('lng', 10, 7)->nullable(); // Longitude
            $table->string('aliases')->nullable(); // Alternative names, comma-separated
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['name', 'city']);
            $table->index('arrondissement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('neighborhoods');
    }
};
