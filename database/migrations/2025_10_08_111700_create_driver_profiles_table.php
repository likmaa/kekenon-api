<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->string('vehicle_number')->nullable();
            $table->string('license_number')->nullable();
            $table->string('photo')->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->json('documents')->nullable(); // JSON for extra docs
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
