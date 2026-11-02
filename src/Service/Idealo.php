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

    /** Standard-Lieferzeit, wenn weder in Cover (C2WART.LIEFER_ZEIT) noch in den Feed-Einstellungen etwas steht */
    public const DEFAULT_DELIVERY = 'Lieferung in 3-5 Werktagen';

    /**
     * Relativierungen, die idealo bei der Lieferzeit nicht zulässt (werden am Anfang entfernt).
     */
    private const RELATIVIZERS = '/^(?:(?:ca\.|i\.\s?d\.\s?r\.)\s*|(?:ca|circa|zirka|etwa|ungefähr|rund|voraussichtlich|in\s+der\s+regel|meist(?:ens)?)(?:\s+|$))/iu';

    /**
     * Macht aus dem Cover-Wert (Freitext wie im Shop) einen idealo-tauglichen Lieferzeit-Text:
     *   "ca. 1 - 2 Wochen" -> "Lieferung in 1-2 Wochen"
     *   "7" / "7 Tage"     -> "Lieferung in 7 Werktagen"
     *   "5-7" / "5 bis 7 Werktage" -> "Lieferung in 5-7 Werktagen"
     *   "2 Monate"         -> "Lieferung in 2 Monaten"
     *   sonstiger Text     -> unverändert (nur führendes "ca.", "etwa", "voraussichtlich" … entfernt)
     * Leer -> null (dann gilt die Standard-Lieferzeit)
     */
    public static function formatDelivery(?string $raw): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');
        if ($text === '' || $text === '0') {
            return null;
        }

        // "ca.", "etwa", "voraussichtlich" … vorne entfernen (auch mehrfach, z.B. "voraussichtlich ca.")
        do {
            $before = $text;
            $text = trim(preg_replace(self::RELATIVIZERS, '', $text) ?? $text);
        } while ($text !== $before && $text !== '');
        if ($text === '') {
            return null;
        }

        // Zahl oder Spanne mit optionaler Einheit
        if (preg_match('/^(?:lieferung\s+in\s+)?(\d{1,3})\s*(?:(?:-|–|bis)\s*(\d{1,3}))?\s*(werktagen?|werktage|tagen?|tage|wt\.?|wochen?|monaten?|monate|monat)?\.?$/iu', $text, $m)) {
            $from = (int) $m[1];
            $to = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            $unit = mb_strtolower($m[3] ?? '');

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

        return $text;
    }
}
