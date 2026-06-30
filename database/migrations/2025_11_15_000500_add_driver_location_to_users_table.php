<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_lat')) {
                $table->decimal('last_lat', 10, 7)->nullable()->after('is_online');
            }
            if (!Schema::hasColumn('users', 'last_lng')) {
                $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
            }
            if (!Schema::hasColumn('users', 'last_location_at')) {
                $table->timestamp('last_location_at')->nullable()->after('last_lng');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_location_at')) {
                $table->dropColumn('last_location_at');
            }
            if (Schema::hasColumn('users', 'last_lng')) {
                $table->dropColumn('last_lng');
            }
            if (Schema::hasColumn('users', 'last_lat')) {
                $table->dropColumn('last_lat');
            }
        });
    }
};
