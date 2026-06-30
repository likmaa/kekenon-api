<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('rider_id')->constrained('users');
            $table->foreignId('driver_id')->constrained('users');

            $table->string('status', 32)->index();

            $table->unsignedBigInteger('fare_amount')->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('driver_earnings_amount')->default(0);
            $table->char('currency', 3)->default('XOF');

            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('duration_s')->default(0);

            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->string('cancellation_reason', 120)->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'completed_at']);
            $table->index(['status', 'completed_at']);
            $table->index(['driver_id', 'status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
