<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Allow negative balance values in wallet_transactions.
     * This is needed because for cash rides, the driver collects the fare
     * and the commission is deducted from their wallet, which may go negative
     * if they haven't topped up yet (creating a debt to the platform).
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_before` BIGINT NOT NULL');
            DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_after` BIGINT NOT NULL');
            DB::statement('ALTER TABLE `wallets` MODIFY `balance` BIGINT NOT NULL DEFAULT 0');

            return;
        }

        // SQLite : pas de MODIFY ; INTEGER accepte déjà les valeurs négatives (typage dynamique).
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_before` BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_after` BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE `wallets` MODIFY `balance` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
