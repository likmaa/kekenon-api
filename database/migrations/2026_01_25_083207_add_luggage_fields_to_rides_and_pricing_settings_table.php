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
            $table->integer('luggage_count')->default(0)->after('has_baggage');
        });

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->integer('luggage_unit_price')->default(100)->after('min_fare');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('luggage_count');
        });

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn('luggage_unit_price');
        });
    }
};
