<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ergänzt fehlende GTIN/EAN/ISBN aus der Cover-Datenbank (Oracle).
 *
 * Zuordnung über die SKU (C2WART.SKU = g:id im Feed). Die Quellen werden der Reihe nach abgefragt,
 * die erste gültige Nummer gewinnt. Standard (ermittelt mit `cover:find-gtin --coverage`):
 *
 *   COVER_GTIN_SOURCES=BUAUFL.EAN:L_AUSG_NR+L_AUFL_NR,BUAUSGAB.EAN:L_AUSG_NR,BUAUSGAB.ISBN:L_AUSG_NR
 *
 * Format je Quelle: TABELLE.SPALTE:VERKNÜPFUNG (Schlüssel mit + verbunden).
 *
 * Schutz vor falschen Varianten-EANs: Quellen auf Ausgabe-Ebene (Verknüpfung ohne L_AUFL_NR) werden
 * nur für Artikel ohne Varianten genutzt, d.h. wenn es zur Ausgabe genau einen aktiven Shop-Artikel gibt.
 * Sonst bekämen alle Größen/Farben dieselbe EAN.
 *
 * Abschalten mit COVER_GTIN_SOURCES=0. Ist Cover nicht erreichbar, läuft der Import ohne Ergänzung weiter.
 */
class CoverGtinLookup
{
    public const DEFAULT_SOURCES = 'BUAUFL.EAN:L_AUSG_NR+L_AUFL_NR,BUAUSGAB.EAN:L_AUSG_NR,BUAUSGAB.ISBN:L_AUSG_NR';

    private const IDENTIFIER = '/^[A-Z][A-Z0-9_$#]{0,29}$/';

    private ?string $lastError = null;

    /** @var array<int, array{label: string, sql: string}>|null */
    private ?array $queries = null;

    public function __construct(
        private ManagerRegistry $registry,
        #[Autowire(env: 'default:cover_gtin_sources_default:COVER_GTIN_SOURCES')] private ?string $sources,
    ) {}

    public function isEnabled(): bool
    {
        return $this->getQueries() !== [];
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** @return string[] z.B. ["BUAUFL.EAN (Variante)", "BUAUSGAB.EAN (Ausgabe, nur ohne Varianten)"] */
    public function describeSources(): array
    {
        return array_column($this->getQueries(), 'label');
    }

    /**
     * @param string[] $skus
     * @return array<string, string> SKU => gültige GTIN (nur Treffer)
     */
    public function lookup(array $skus): array
    {
        $this->lastError = null;
        $missing = array_values(array_unique(array_map('strval', $skus)));
        $result = [];
        if (!$missing || !$this->isEnabled()) {
            return [];
        }

        try {
            $connection = $this->registry->getConnection('cover');
            foreach ($this->getQueries() as $query) {
                if (!$missing) {
                    break;
                }
                // Oracle erlaubt max. 1000 Werte in IN (...)
                foreach (array_chunk($missing, 900) as $chunk) {
                    $rows = $connection->executeQuery($query['sql'], ['skus' => $chunk], ['skus' => ArrayParameterType::STRING])
                        ->fetchAllNumeric();
                    foreach ($rows as [$sku, $value]) {
                        $sku = trim((string) $sku);
                        if ($sku === '' || isset($result[$sku])) {
                            continue;
                        }
                        $gtin = Gtin::normalize($value === null ? null : (string) $value);
                        if ($gtin !== null) {
                            $result[$sku] = $gtin;
                        }
                    }
                }
                $missing = array_values(array_filter($missing, fn ($sku) => !isset($result[$sku])));
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }

        return $result;
    }

    /** @return array<int, array{label: string, sql: string}> */
    private function getQueries(): array
    {
        if ($this->queries !== null) {
            return $this->queries;
        }

        $this->queries = [];
        $config = trim((string) $this->sources);
        if ($config === '' || $config === '0') {
            return $this->queries;
        }

        foreach (explode(',', $config) as $source) {
            $source = strtoupper(trim($source));
            if (!preg_match('/^([A-Z0-9_$#]+)\.([A-Z0-9_$#]+)(?::([A-Z0-9_$#+]+))?$/', $source, $m)) {
                continue;
            }
            [, $table, $column] = $m;
            $keys = array_filter(explode('+', $m[3] ?? 'L_AUSG_NR'));
            if (!preg_match(self::IDENTIFIER, $table) || !preg_match(self::IDENTIFIER, $column)) {
                continue;
            }
            foreach ($keys as $key) {
                if (!preg_match(self::IDENTIFIER, $key)) {
                    continue 2;
                }
            }

            if ($table === 'C2WART') {
                $this->queries[] = [
                    'label' => "C2WART.$column",
                    'sql' => "SELECT TRIM(c.SKU), TRIM(c.$column) FROM C2WART c WHERE TRIM(c.SKU) IN (:skus) AND c.$column IS NOT NULL",
                ];
                continue;
            }

            $conditions = array_map(
                fn ($k) => $k === 'SKU' ? 'TRIM(t.SKU) = TRIM(c.SKU)' : "t.$k = c.$k",
                $keys
            );
            $sql = "SELECT TRIM(c.SKU), TRIM(t.$column) FROM C2WART c JOIN $table t ON " . implode(' AND ', $conditions)
                . " WHERE TRIM(c.SKU) IN (:skus) AND t.$column IS NOT NULL";

            // Ausgabe-Ebene (ohne Auflage/Variante): nur Artikel ohne Varianten
            $variantLevel = in_array('L_AUFL_NR', $keys, true) || in_array('SKU', $keys, true);
            if (!$variantLevel) {
                $sql .= ' AND (SELECT COUNT(*) FROM C2WART c2 WHERE c2.L_AUSG_NR = c.L_AUSG_NR AND c2.SHOP_STATUS = 1) <= 1';
            }

            $this->queries[] = [
                'label' => "$table.$column" . ($variantLevel ? ' (Variante)' : ' (Ausgabe, nur Artikel ohne Varianten)'),
                'sql' => $sql,
            ];
        }

        return $this->queries;
    }
}
