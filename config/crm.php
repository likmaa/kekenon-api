<?php

/**
 * §20.8 — Segmentation CRM passagers (seuils tunables).
 */
return [
    'vip_total_spent' => (int) env('CRM_VIP_TOTAL_SPENT', 50000), // dépense cumulée min pour VIP (XOF)
    'new_days' => (int) env('CRM_NEW_DAYS', 30),                  // inscrit depuis <= N jours = nouveau
    'inactive_days' => (int) env('CRM_INACTIVE_DAYS', 60),       // aucune course depuis N jours = inactif
    'active_rides_30d' => (int) env('CRM_ACTIVE_RIDES_30D', 2),  // courses sur 30j pour être "actif"
];
