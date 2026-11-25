<?php

namespace App\Service;

/**
 * Feste Werte aus der idealo-CSV-Spezifikation (Stand 10/2026).
 */
final class Idealo
{
    /** Versanddienstleister für die Spalte deliveryCosts_<key> */
    public const CARRIERS = [
        'dhl' => 'DHL',
        'dhl_go_green' => 'DHL GoGreen',
        'deutsche_post' => 'Deutsche Post',
        'dpd' => 'DPD',
        'gls' => 'GLS',
        'gls_think_green' => 'GLS ThinkGreen',
        'hermes' => 'Hermes',
        'ups' => 'UPS',
        'fedex' => 'FedEx',
        'tnt' => 'TNT',
        'trans_o_flex' => 'trans-o-flex',
        'german_express_logistics' => 'German Express Logistics',
        'pick_point' => 'PickPoint',
        'spedition' => 'Spedition',
        'download' => 'Download',
    ];

    /** Zahlungsarten für die Spalten paymentCosts_<key> */
    public const PAYMENT_METHODS = [
        'paypal' => 'PayPal',
        'invoice' => 'Rechnung',
        'cash_in_advance' => 'Vorkasse',
        'direct_debit' => 'Lastschrift',
        'credit_card' => 'Kreditkarte',
        'cash_on_delivery' => 'Nachnahme',
        'amazon_payment' => 'Amazon Pay',
        'electronic_payment_standard' => 'Sofortüberweisung / Klarna',
    ];

    public const FULFILLMENT_TYPE = 'Parcel_Service';

    /** UTM-Parameter für die Auswertung in Google Analytics, werden an jede Produkt-URL gehängt */
    public const TRACKING = [
        'utm_source' => 'idealo',
        'utm_medium' => 'link',
        'utm_campaign' => 'idealo_pareyshop_link',
    ];

    /** Standard-Lieferzeit, wenn weder in Cover (C2WART.LIEFER_ZEIT) noch in den Feed-Einstellungen etwas steht */
    public const DEFAULT_DELIVERY = 'Lieferung in 3-5 Werktagen';

    /**
     * Relativierungen, die idealo bei der Lieferzeit nicht zulässt (werden überall im Text entfernt).
     */
    private const RELATIVIZERS = '/(?<!\pL)(?:ca\.|i\.\s?d\.\s?r\.|(?:ca|circa|zirka|etwa|ungefähr|rund|voraussichtlich|in\s+der\s+regel|meist(?:ens)?)(?!\pL))\s*/iu';

    /** Vorangestellte Bezeichnung wie "Lieferzeit:" oder "Lieferung in" */
    private const LABEL = '/^(?:lieferzeit|lieferdauer|lieferung)\s*:?\s*(?:in(?:nerhalb)?(?:\s+von)?\s+)?/iu';

    /** Zahl oder Spanne, z.B. "1-2", "5 bis 7", "3–4" */
    private const RANGE = '(\d{1,3})\s*(?:(?:-|–|bis)\s*(\d{1,3}))?\s*';

    /** Einheit: (Werk-)Tage, Wochen, Monate */
    private const UNIT = '(werktag(?:en?)?|wt\.?|tag(?:en?)?|wochen?|monat(?:en?)?)';

    /**
     * Macht aus dem Cover-Wert (Freitext wie im Shop) einen idealo-tauglichen Lieferzeit-Text:
     *   "ca. 1 - 2 Wochen" -> "Lieferung in 1-2 Wochen"
     *   "Lieferzeit ca. 1-2 Wochen (durch Hersteller)" -> "Lieferung in 1-2 Wochen"
     *   "7" / "7 Tage"     -> "Lieferung in 7 Werktagen"
     *   "5-7" / "5 bis 7 Werktage" -> "Lieferung in 5-7 Werktagen"
     *   "2 Monate"         -> "Lieferung in 2 Monaten"
     *   sonstiger Text     -> ohne "ca.", "etwa", "voraussichtlich" … und ohne Klammerzusätze
     * Leer -> null (dann gilt die Standard-Lieferzeit)
     */
    public static function formatDelivery(?string $raw): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');
        if ($text === '' || $text === '0') {
            return null;
        }

        // Klammerzusätze wie "(durch Hersteller)" und Relativierungen ("ca.", "etwa" …) entfernen
        $text = preg_replace('/\s*\([^)]*\)/u', '', $text) ?? $text;
        $text = preg_replace(self::RELATIVIZERS, '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text, " \t.,;:-");
        if ($text === '') {
            return null;
        }

        // Nur Zahl/Spanne mit optionaler Einheit, z.B. "7", "5-7 Tage", "Lieferzeit: 1-2 Wochen"
        $short = preg_replace(self::LABEL, '', $text) ?? $text;
        if (preg_match('/^' . self::RANGE . '(?:' . self::UNIT . '(?!\pL))?\.?$/iu', $short, $m)
            // sonst Spanne mit Einheit irgendwo im Text, z.B. "versandfertig in 2-3 Tagen"
            || preg_match('/(?<![\d\pL])' . self::RANGE . self::UNIT . '(?!\pL)/iu', $text, $m)) {
            return self::deliveryText((int) $m[1], ($m[2] ?? '') !== '' ? (int) $m[2] : null, $m[3] ?? '');
        }

        return $text;
    }

    /**
     * Hängt die UTM-Parameter an die Produkt-URL (vor einem evtl. "#anker").
     * Schon vorhandene utm_*-Parameter in der URL werden ersetzt.
     */
    public static function trackingUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');

        $params = array_filter(
            $query === '' ? [] : explode('&', $query),
            fn (string $p) => $p !== '' && !str_starts_with(strtolower($p), 'utm_')
        );
        $params[] = http_build_query(self::TRACKING, '', '&', PHP_QUERY_RFC3986);

        return $base . '?' . implode('&', $params) . ($fragment !== null ? '#' . $fragment : '');
    }

    private static function deliveryText(int $from, ?int $to, string $unit): string
    {
        $unit = mb_strtolower($unit);
        if (str_starts_with($unit, 'woch')) {
            [$one, $many] = ['Woche', 'Wochen'];
        } elseif (str_starts_with($unit, 'monat')) {
            [$one, $many] = ['Monat', 'Monaten'];
        } else {
            // ohne Einheit oder (Werk-)Tage: idealo verlangt Werktage
            [$one, $many] = ['Werktag', 'Werktagen'];
        }

        if ($to !== null && $to !== $from) {
            return sprintf('Lieferung in %d-%d %s', min($from, $to), max($from, $to), $many);
        }

        return sprintf('Lieferung in %d %s', $from, $from === 1 ? $one : $many);
    }
}
