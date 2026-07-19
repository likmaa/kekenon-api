<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('package_size', 20)->nullable()->after('package_description');
            $table->string('delivery_code_hash')->nullable()->after('is_fragile');
            $table->text('delivery_code_encrypted')->nullable()->after('delivery_code_hash');
            $table->timestamp('delivery_confirmed_at')->nullable()->after('delivery_code_encrypted');
        });

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->unsignedInteger('delivery_small_fee')->default(0)->after('driver_pack_rides');
            $table->unsignedInteger('delivery_medium_fee')->default(200)->after('delivery_small_fee');
            $table->unsignedInteger('delivery_large_fee')->default(500)->after('delivery_medium_fee');
            $table->unsignedInteger('delivery_fragile_fee')->default(200)->after('delivery_large_fee');
            $table->unsignedInteger('delivery_weight_threshold_kg')->default(5)->after('delivery_fragile_fee');
            $table->unsignedInteger('delivery_extra_kg_fee')->default(100)->after('delivery_weight_threshold_kg');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['package_size', 'delivery_code_hash', 'delivery_code_encrypted', 'delivery_confirmed_at']);
        });

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_small_fee',
                'delivery_medium_fee',
                'delivery_large_fee',
                'delivery_fragile_fee',
                'delivery_weight_threshold_kg',
                'delivery_extra_kg_fee',
            ]);
        });
    }
};
