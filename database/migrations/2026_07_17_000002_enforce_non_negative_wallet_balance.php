<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modèle « zéro dette » : aucun portefeuille ne peut être négatif.
 * On remet à zéro les dettes existantes puis on rétablit la contrainte
 * UNSIGNED (annule 2026_01_15_170000_allow_negative_wallet_balance).
 */
return new class extends Migration {
    public function up(): void
    {
        // Solde effacé : les anciennes dettes ne sont plus recouvrées.
        DB::table('wallets')->where('balance', '<', 0)->update(['balance' => 0]);

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `wallets` MODIFY `balance` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }
        // SQLite : typage dynamique, la garde applicative suffit.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `wallets` MODIFY `balance` BIGINT NOT NULL DEFAULT 0');
        }
    }
};
