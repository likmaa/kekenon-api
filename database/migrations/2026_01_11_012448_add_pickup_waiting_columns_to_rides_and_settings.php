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
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('accepted_at');
        });

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->integer('pickup_grace_period_m')->default(5)->after('stop_rate_per_min');
            $table->integer('pickup_waiting_rate_per_min')->default(10)->after('pickup_grace_period_m');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn(['pickup_grace_period_m', 'pickup_waiting_rate_per_min']);
        });
    }
};
