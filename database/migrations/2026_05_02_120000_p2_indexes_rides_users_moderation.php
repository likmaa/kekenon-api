<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->index('offered_driver_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'is_online', 'is_active'], 'users_role_is_online_is_active_index');
        });

        Schema::table('moderation_logs', function (Blueprint $table) {
            $table->index(['target_id', 'created_at'], 'moderation_logs_target_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex(['offered_driver_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_is_online_is_active_index');
        });

        Schema::table('moderation_logs', function (Blueprint $table) {
            $table->dropIndex('moderation_logs_target_id_created_at_index');
        });
    }
};
