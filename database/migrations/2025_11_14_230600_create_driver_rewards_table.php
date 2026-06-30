<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_rewards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('driver_id')->index();
            $table->unsignedInteger('points_threshold');
            $table->unsignedInteger('amount');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['driver_id', 'points_threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_rewards');
    }
};
