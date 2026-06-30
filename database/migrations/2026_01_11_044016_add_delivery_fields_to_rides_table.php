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
            if (!Schema::hasColumn('rides', 'service_type')) {
                $table->string('service_type', 20)->default('course')->after('status')->index();
            }
            $table->string('recipient_name')->nullable()->after('passenger_phone');
            $table->string('recipient_phone')->nullable()->after('recipient_name');
            $table->text('package_description')->nullable()->after('recipient_phone');
            $table->string('package_weight')->nullable()->after('package_description');
            $table->boolean('is_fragile')->default(false)->after('package_weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn([
                'service_type',
                'recipient_name',
                'recipient_phone',
                'package_description',
                'package_weight',
                'is_fragile'
            ]);
        });
    }
};
