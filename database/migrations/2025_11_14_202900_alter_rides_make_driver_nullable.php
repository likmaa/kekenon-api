<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $row = DB::selectOne("SELECT IS_NULLABLE as n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides' AND COLUMN_NAME = 'driver_id'");
            if (!$row || strtoupper($row->n) === 'NO') {
                $fk = DB::selectOne("SELECT CONSTRAINT_NAME as name FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides' AND COLUMN_NAME = 'driver_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
                if ($fk && !empty($fk->name)) {
                    DB::statement("ALTER TABLE `rides` DROP FOREIGN KEY `{$fk->name}`");
                }
                DB::statement("ALTER TABLE `rides` MODIFY `driver_id` BIGINT UNSIGNED NULL");
                DB::statement("ALTER TABLE `rides` ADD CONSTRAINT `rides_driver_id_foreign` FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`)");
            }
            return;
        }
        if ($driver === 'pgsql') {
            $row = DB::selectOne("SELECT is_nullable as n FROM information_schema.columns WHERE table_name = 'rides' AND column_name = 'driver_id' LIMIT 1");
            if ($row && strtolower($row->n) === 'no') {
                DB::statement('ALTER TABLE rides ALTER COLUMN driver_id DROP NOT NULL');
            }
            return;
        }
        if ($driver === 'sqlite') {
            $cols = DB::select("PRAGMA table_info('rides')");
            $notNull = null;
            foreach ($cols as $c) {
                if ($c->name === 'driver_id') { $notNull = $c->notnull; break; }
            }
            if ($notNull === 1) {
                DB::statement('PRAGMA foreign_keys=off');
                DB::beginTransaction();
                Schema::create('rides_tmp', function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->unsignedBigInteger('rider_id');
                    $table->unsignedBigInteger('driver_id')->nullable();
                    $table->string('status', 32)->index();
                    $table->unsignedBigInteger('fare_amount')->default(0);
                    $table->unsignedBigInteger('commission_amount')->default(0);
                    $table->unsignedBigInteger('driver_earnings_amount')->default(0);
                    $table->char('currency', 3)->default('XOF');
                    $table->unsignedInteger('distance_m')->default(0);
                    $table->unsignedInteger('duration_s')->default(0);
                    $table->timestamp('accepted_at')->nullable()->index();
                    $table->timestamp('started_at')->nullable()->index();
                    $table->timestamp('completed_at')->nullable()->index();
                    $table->timestamp('cancelled_at')->nullable()->index();
                    $table->string('cancellation_reason', 120)->nullable();
                    $table->timestamps();
                });
                DB::statement('INSERT INTO rides_tmp (id,rider_id,driver_id,status,fare_amount,commission_amount,driver_earnings_amount,currency,distance_m,duration_s,accepted_at,started_at,completed_at,cancelled_at,cancellation_reason,created_at,updated_at) SELECT id,rider_id,driver_id,status,fare_amount,commission_amount,driver_earnings_amount,currency,distance_m,duration_s,accepted_at,started_at,completed_at,cancelled_at,cancellation_reason,created_at,updated_at FROM rides');
                Schema::drop('rides');
                Schema::rename('rides_tmp', 'rides');
                Schema::table('rides', function (Blueprint $table) {
                    $table->index(['driver_id', 'completed_at']);
                    $table->index(['status', 'completed_at']);
                    $table->index(['driver_id', 'status', 'completed_at']);
                });
                DB::commit();
                DB::statement('PRAGMA foreign_keys=on');
            }
            return;
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `rides` MODIFY `driver_id` BIGINT UNSIGNED NOT NULL');
            return;
        }
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE rides ALTER COLUMN driver_id SET NOT NULL');
            return;
        }
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off');
            DB::beginTransaction();
            Schema::create('rides_tmp', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rider_id');
                $table->unsignedBigInteger('driver_id');
                $table->string('status', 32)->index();
                $table->unsignedBigInteger('fare_amount')->default(0);
                $table->unsignedBigInteger('commission_amount')->default(0);
                $table->unsignedBigInteger('driver_earnings_amount')->default(0);
                $table->char('currency', 3)->default('XOF');
                $table->unsignedInteger('distance_m')->default(0);
                $table->unsignedInteger('duration_s')->default(0);
                $table->timestamp('accepted_at')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamp('cancelled_at')->nullable()->index();
                $table->string('cancellation_reason', 120)->nullable();
                $table->timestamps();
            });
            DB::statement('INSERT INTO rides_tmp (id,rider_id,driver_id,status,fare_amount,commission_amount,driver_earnings_amount,currency,distance_m,duration_s,accepted_at,started_at,completed_at,cancelled_at,cancellation_reason,created_at,updated_at) SELECT id,rider_id,driver_id,status,fare_amount,commission_amount,driver_earnings_amount,currency,distance_m,duration_s,accepted_at,started_at,completed_at,cancelled_at,cancellation_reason,created_at,updated_at FROM rides');
            Schema::drop('rides');
            Schema::rename('rides_tmp', 'rides');
            Schema::table('rides', function (Blueprint $table) {
                $table->index(['driver_id', 'completed_at']);
                $table->index(['status', 'completed_at']);
                $table->index(['driver_id', 'status', 'completed_at']);
            });
            DB::commit();
            DB::statement('PRAGMA foreign_keys=on');
            return;
        }
    }
};
