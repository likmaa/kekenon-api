<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moderator_id')->nullable();
            $table->string('moderator_name')->nullable();
            $table->string('action', 50); // suspended, banned, warned, reinstated
            $table->unsignedBigInteger('target_id');
            $table->string('target_name')->nullable();
            $table->string('target_type', 50)->nullable(); // driver, passenger
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moderation_logs');
    }
};
