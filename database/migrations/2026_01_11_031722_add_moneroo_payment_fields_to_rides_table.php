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
            $table->string('payment_status')->default('pending')->after('status'); // pending, completed, failed
            $table->text('payment_link')->nullable()->after('payment_status');
            $table->string('external_reference')->nullable()->after('payment_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'payment_link', 'external_reference']);
        });
    }
};
