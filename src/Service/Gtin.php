<?php

namespace App\Service;

/**
 * Prüft und normalisiert GTIN/EAN/ISBN.
 *
 * - Akzeptiert GTIN-8, GTIN-12 (UPC), GTIN-13 (EAN/ISBN-13) und GTIN-14 mit gültiger Prüfziffer.
 * - ISBN-10 (auch mit "X") wird in ISBN-13 (978...) umgerechnet.
 * - Bindestriche und Leerzeichen werden entfernt ("978-3-440-12345-6").
 */
final class Gtin
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtoupper(preg_replace('/[\s\-]/', '', trim($value)) ?? '');
        if ($value === '') {
            return null;
        }

        // ISBN-10 -> ISBN-13
        if (preg_match('/^\d{9}[\dX]$/', $value)) {
            if (!self::isValidIsbn10($value)) {
                return null;
            }
            $core = '978' . substr($value, 0, 9);

            return $core . self::checkDigit($core);
        }

        if (!preg_match('/^\d+$/', $value) || !in_array(strlen($value), [8, 12, 13, 14], true)) {
            return null;
        }
        // Nur Nullen o.ä. Platzhalter verwerfen
        if (ltrim($value, '0') === '') {
            return null;
        }

        $body = substr($value, 0, -1);
        if (self::checkDigit($body) !== (int) substr($value, -1)) {
            return null;
        }

        // Interne/ladeneigene Nummern (GS1 "Restricted Circulation") sind keine weltweit gültigen GTINs
        // und werden von Google/idealo abgelehnt: EAN-13 mit 2…, UPC-12 mit 2…/4…, EAN-8 mit 0…/2…
        $len = strlen($value);
        if (($len === 13 && $value[0] === '2')
            || ($len === 12 && in_array($value[0], ['2', '4'], true))
            || ($len === 8 && in_array($value[0], ['0', '2'], true))) {
            return null;
        }

        return $value;
    }

    private static function checkDigit(string $body): int
    {
        $sum = 0;
        $digits = strrev($body);
        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }

    private static function isValidIsbn10(string $isbn): bool
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = $isbn[$i] === 'X' ? 10 : (int) $isbn[$i];
            $sum += $digit * (10 - $i);
        }

        return $sum % 11 === 0;
    }
}
