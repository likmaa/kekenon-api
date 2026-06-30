<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passenger_inbox_notifications', function (Blueprint $table) {
            $table->foreignId('admin_notification_id')
                ->nullable()
                ->after('user_id')
                ->constrained('notifications')
                ->cascadeOnDelete();
            $table->index('admin_notification_id');
        });
    }

    public function down(): void
    {
        Schema::table('passenger_inbox_notifications', function (Blueprint $table) {
            $table->dropForeign(['admin_notification_id']);
        });
    }
};
