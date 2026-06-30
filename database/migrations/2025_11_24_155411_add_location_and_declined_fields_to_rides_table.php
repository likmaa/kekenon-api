<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            if (!Schema::hasColumn('rides', 'pickup_lat')) {
                $table->decimal('pickup_lat', 10, 7)->nullable()->after('duration_s');
                $table->decimal('pickup_lng', 10, 7)->nullable()->after('pickup_lat');
            }

            if (!Schema::hasColumn('rides', 'dropoff_lat')) {
                $table->decimal('dropoff_lat', 10, 7)->nullable()->after('pickup_lng');
                $table->decimal('dropoff_lng', 10, 7)->nullable()->after('dropoff_lat');
            }

            if (!Schema::hasColumn('rides', 'pickup_address')) {
                $table->string('pickup_address')->nullable()->after('dropoff_lng');
            }

            if (!Schema::hasColumn('rides', 'dropoff_address')) {
                $table->string('dropoff_address')->nullable()->after('pickup_address');
            }

            if (!Schema::hasColumn('rides', 'declined_driver_ids')) {
                $table->json('declined_driver_ids')->nullable()->after('offered_driver_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            if (Schema::hasColumn('rides', 'declined_driver_ids')) {
                $table->dropColumn('declined_driver_ids');
            }

            if (Schema::hasColumn('rides', 'dropoff_address')) {
                $table->dropColumn('dropoff_address');
            }

            if (Schema::hasColumn('rides', 'pickup_address')) {
                $table->dropColumn('pickup_address');
            }

            if (Schema::hasColumn('rides', 'dropoff_lat')) {
                $table->dropColumn(['dropoff_lat', 'dropoff_lng']);
            }

            if (Schema::hasColumn('rides', 'pickup_lat')) {
                $table->dropColumn(['pickup_lat', 'pickup_lng']);
            }
        });
    }
};
