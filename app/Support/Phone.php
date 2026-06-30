<?php

namespace App\Support;

class Phone
{
    /**
     * Normalisation très simple en format type E.164.
     * Pour ce projet, on force les numéros béninois au format +22901XXXXXXXX
     * pour rester cohérent avec l'app mobile.
     */
    public static function normalize(string $phone, string $defaultCountryCode = '+229'): string
    {
        // Remove spaces, dashes, dots, parentheses
        $digits = preg_replace('/[\s\-\.\(\)]/', '', $phone);
        if ($digits === null) {
            return $phone;
        }

        // Replace leading 00 with +
        $digits = preg_replace('/^00/', '+', $digits);

        // Si le numéro commence déjà par +229, on le retourne tel quel
        if (str_starts_with($digits, '+229')) {
            return $digits;
        }

        // Si le numéro commence par 229 (sans +), on préfixe simplement par +
        if (str_starts_with($digits, '229')) {
            return '+' . $digits;
        }

        // Si le numéro commence déjà par un + (autre pays), on le retourne tel quel
        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        // Numéro local : on préfixe seulement avec le code pays par défaut, sans ajouter 01
        return $defaultCountryCode . $digits;
    }
}
