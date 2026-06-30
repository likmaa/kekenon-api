<?php

namespace App\Support;

/**
 * Logique centralisée des règles de dette chauffeur (§20.6).
 * `balance` = solde wallet (négatif = dette). On raisonne sur le montant de dette = abs(min(balance, 0)).
 */
class DriverDebt
{
    public static function amount(?float $balance): int
    {
        $b = (float) ($balance ?? 0);
        return $b < 0 ? (int) abs($b) : 0;
    }

    public static function notifyThreshold(): int
    {
        return (int) config('debt.notify_threshold', 5000);
    }

    public static function alertThreshold(): int
    {
        return (int) config('debt.alert_threshold', 10000);
    }

    public static function blockThreshold(): int
    {
        return (int) config('debt.block_threshold', 15000);
    }

    /** Retourne 'ok' | 'notify' | 'alert' | 'blocked'. */
    public static function level(?float $balance): string
    {
        $debt = self::amount($balance);
        if ($debt >= self::blockThreshold()) {
            return 'blocked';
        }
        if ($debt >= self::alertThreshold()) {
            return 'alert';
        }
        if ($debt >= self::notifyThreshold()) {
            return 'notify';
        }
        return 'ok';
    }

    /** Le chauffeur est-il bloqué pour cause de dette (niveau 3) ? */
    public static function isBlockedByDebt(?float $balance): bool
    {
        return self::amount($balance) >= self::blockThreshold();
    }
}
