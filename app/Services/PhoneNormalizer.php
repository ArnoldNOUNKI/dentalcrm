<?php

namespace App\Services;

/**
 * Normalise les numéros au format E.164 (+33…), sans dépendance externe.
 * Gère les formats FR courants : 03 87 00 00 00, 0033387000000, +33 (0)3 87…, 33387000000, 387000000.
 */
class PhoneNormalizer
{
    public static function normalize(?string $raw, string $defaultCountry = 'FR'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $hasPlus = str_starts_with($raw, '+');
        // +33 (0)3… : on supprime le (0) de courtoisie
        $raw = preg_replace('/\(0\)/', '', $raw);
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '' || strlen($digits) < 6) {
            return null;
        }

        if ($hasPlus) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        $prefix = self::countryPrefix($defaultCountry);

        // Format national avec 0 initial
        if (str_starts_with($digits, '0') && strlen($digits) === 10 && $prefix === '33') {
            return '+33'.substr($digits, 1);
        }

        // Indicatif déjà présent sans le +
        if (str_starts_with($digits, $prefix) && strlen($digits) >= 11) {
            return '+'.$digits;
        }

        // 9 chiffres FR sans le 0 (Excel supprime souvent le zéro de tête)
        if ($prefix === '33' && strlen($digits) === 9) {
            return '+33'.$digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+'.$prefix.ltrim($digits, '0');
        }

        return '+'.$digits;
    }

    /** Affichage lisible : +33 3 87 00 00 00 */
    public static function format(?string $e164): ?string
    {
        if (! $e164) {
            return null;
        }

        if (str_starts_with($e164, '+33') && strlen($e164) === 12) {
            $n = substr($e164, 3);

            return '+33 '.$n[0].' '.implode(' ', str_split(substr($n, 1), 2));
        }

        return $e164;
    }

    /** Numéros équivalents à rechercher en base (avec et sans +). */
    public static function variants(?string $raw): array
    {
        $n = self::normalize($raw);

        return $n ? array_values(array_unique([$n, ltrim($n, '+')])) : [];
    }

    private static function countryPrefix(string $country): string
    {
        return match (strtoupper($country)) {
            'BE' => '32', 'LU' => '352', 'CH' => '41', 'DE' => '49', 'CM' => '237', 'ES' => '34', 'IT' => '39',
            default => '33',
        };
    }
}
