<?php

/**
 * Seuils de dette chauffeur (§20.6).
 * La dette = solde wallet négatif (en valeur absolue, XOF).
 *  - Niveau 1 (> notify) : notification
 *  - Niveau 2 (> alert)  : alerte (tableau de bord)
 *  - Niveau 3 (>= block) : blocage automatique (ne peut plus passer en ligne ni accepter)
 */
return [
    'notify_threshold' => (int) env('DRIVER_DEBT_NOTIFY', 5000),
    'alert_threshold'  => (int) env('DRIVER_DEBT_ALERT', 10000),
    'block_threshold'  => (int) env('DRIVER_DEBT_BLOCK', 15000),
];
