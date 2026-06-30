<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ride_id');
            $table->unsignedBigInteger('driver_id')->index();
            $table->unsignedBigInteger('passenger_id')->index();
            $table->unsignedTinyInteger('stars');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['ride_id', 'passenger_id']);
            $table->index(['driver_id', 'stars']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
