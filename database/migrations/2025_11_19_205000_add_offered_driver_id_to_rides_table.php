<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            if (!Schema::hasColumn('rides', 'offered_driver_id')) {
                $table->unsignedBigInteger('offered_driver_id')->nullable()->after('driver_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            if (Schema::hasColumn('rides', 'offered_driver_id')) {
                $table->dropColumn('offered_driver_id');
            }
        });
    }
};
