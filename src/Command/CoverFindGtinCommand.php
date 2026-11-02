<?php

namespace App\Command;

use App\Entity\Mysql\Product;
use App\Service\CoverGtinLookup;
use App\Service\Gtin;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cover:find-gtin',
    description: 'Sucht in Cover nach Spalten mit ISBN/EAN/GTIN und schlägt die Einstellungen für COVER_GTIN_* vor',
)]
class CoverFindGtinCommand extends Command
{
    private const IDENTIFIER = '/^[A-Z][A-Z0-9_$#]{0,29}$/';

    /** Spaltennamen, die tatsächlich eine Nummer enthalten (keine Kennzeichen/Typen) */
    private const VALUE_COLUMNS = '/^(EAN|ISBN|GTIN|EAN_?CODE|EAN_?13|ISBN_?13|ISBN_EXT|BARCODE|GTIN_?\d*)$/';

    /** Views, Temp-/Archivtabellen und Bewegungsdaten (Rechnungen, Positionen, Recherche) standardmäßig überspringen */
    private const SKIP_TABLES = '/(VIEW|VIE|VI)$|TMP|ARCHIV|FAK|TRBUPOS|RESUCH|LAGAUF/';

    /** Tabellen, die zuerst geprüft werden */
    private const PREFERRED = ['BUAUSGAB', 'BUAUFL', 'C2WIMP', 'C2WART'];

    private array $columnCache = [];

    public function __construct(
        private ManagerRegistry $registry,
        private EntityManagerInterface $mysqlEntityManager,
        private CoverGtinLookup $gtinLookup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sku', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Eine oder mehrere SKUs zum Testen (z.B. BU-30010176-01-PPZ). Ohne Angabe werden bis zu 20 Artikel aus dem Feed genommen.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Auch Views, Temp-/Archivtabellen, Bewegungsdaten und Kennzeichen-Spalten prüfen (langsam)')
            ->addOption('coverage', null, InputOption::VALUE_NONE, 'Statt einzelner SKUs: für alle Shop-Artikel (C2WART, SHOP_STATUS=1) zählen, wie viele je Spalte eine gültige Nummer haben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ISBN/EAN/GTIN in Cover suchen');
        $conn = $this->registry->getConnection('cover');
        $all = (bool) $input->getOption('all');

        // 1. Kandidaten-Spalten im eigenen Schema
        $rows = $conn->fetchAllNumeric(
            "SELECT table_name, column_name FROM user_tab_columns
             WHERE column_name LIKE '%ISBN%' OR column_name LIKE '%EAN%' OR column_name LIKE '%GTIN%' OR column_name LIKE '%BARCODE%'
             ORDER BY table_name, column_name"
        );
        $candidates = []; // Tabelle => [Spalten]
        foreach ($rows as [$table, $column]) {
            $table = strtoupper(trim($table));
            $column = strtoupper(trim($column));
            if (!preg_match(self::IDENTIFIER, $table) || !preg_match(self::IDENTIFIER, $column)) {
                continue;
            }
            if (!$all && (preg_match(self::SKIP_TABLES, $table) || !preg_match(self::VALUE_COLUMNS, $column))) {
                continue;
            }
            $candidates[$table][] = $column;
        }

        // Nur Tabellen, die sich mit C2WART verknüpfen lassen (über L_AUSG_NR oder SKU)
        $joins = [];
        foreach (array_keys($candidates) as $table) {
            $cols = $this->columns($conn, $table);
            $options = [];
            if ($table === 'C2WART') {
                $options[] = [];
            } else {
                if (in_array('L_AUSG_NR', $cols, true) && in_array('L_AUFL_NR', $cols, true)) {
                    $options[] = ['L_AUSG_NR', 'L_AUFL_NR'];
                }
                if (in_array('L_AUSG_NR', $cols, true)) {
                    $options[] = ['L_AUSG_NR'];
                }
                if (in_array('SKU', $cols, true)) {
                    $options[] = ['SKU'];
                }
            }
            if ($options) {
                $joins[$table] = $options;
            }
        }
        uksort($joins, function ($a, $b) {
            $pa = array_search($a, self::PREFERRED, true);
            $pb = array_search($b, self::PREFERRED, true);
            return [$pa === false ? 99 : $pa, $a] <=> [$pb === false ? 99 : $pb, $b];
        });

        if (!$joins) {
            $io->warning('Keine passende Tabelle gefunden, die sich über L_AUSG_NR oder SKU mit C2WART verknüpfen lässt. Mit --all erneut versuchen.');
            return Command::SUCCESS;
        }

        $io->section('Geprüfte Tabellen');
        $io->table(['Tabelle', 'Spalten', 'Verknüpfung mit C2WART'], array_map(
            fn ($t) => [$t, implode(', ', $candidates[$t]), implode(' | ', array_map(fn ($k) => $k ? implode('+', $k) : '(direkt)', $joins[$t]))],
            array_keys($joins)
        ));

        if ($input->getOption('coverage')) {
            return $this->coverage($io, $conn, $candidates, $joins);
        }

        // 2. Beispiel-SKUs
        $skus = $input->getArgument('sku');
        if (!$skus) {
            // 20 Artikel gleichmäßig über alle importierten Produkte verteilt
            $allSkus = $this->mysqlEntityManager->createQuery('SELECT p.remoteId FROM ' . Product::class . ' p ORDER BY p.id ASC')
                ->setMaxResults(20000)->getSingleColumnResult();
            $step = max(1, intdiv(count($allSkus), 20));
            for ($i = 0; $i < count($allSkus) && count($skus) < 20; $i += $step) {
                $skus[] = $allSkus[$i];
            }
        }
        if (!$skus) {
            $io->warning('Keine Beispiel-SKUs vorhanden (noch kein Feed importiert). Bitte SKUs angeben, z.B.: php bin/console cover:find-gtin BU-30010176-01-PPZ');
            return Command::SUCCESS;
        }

        // 3. Werte je SKU prüfen
        $result = [];
        $score = []; // "Tabelle|Join|Spalte" => Anzahl gültiger Werte
        $io->progressStart(count($skus));
        foreach ($skus as $sku) {
            $c2 = $conn->fetchAssociative('SELECT L_AUSG_NR, L_AUFL_NR, SKU FROM C2WART WHERE TRIM(SKU) = ?', [trim($sku)]);
            $io->progressAdvance();
            if (!$c2) {
                $result[] = [$sku, '-', '-', 'SKU nicht in C2WART'];
                continue;
            }
            $c2 = array_change_key_case($c2, CASE_UPPER);

            foreach ($joins as $table => $options) {
                foreach ($options as $keys) {
                    $where = $keys ? implode(' AND ', array_map(
                        fn ($k) => $k === 'SKU' ? 'TRIM(SKU) = TRIM(?)' : "$k = ?", $keys
                    )) : 'TRIM(SKU) = TRIM(?)';
                    $params = $keys ? array_map(fn ($k) => $c2[$k], $keys) : [$c2['SKU']];
                    $select = implode(', ', array_map(fn ($c) => "TRIM($c)", $candidates[$table]));
                    try {
                        $row = $conn->fetchNumeric("SELECT $select FROM $table WHERE $where", $params);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if (!$row) {
                        continue;
                    }
                    $found = false;
                    foreach ($candidates[$table] as $i => $column) {
                        $value = trim((string) ($row[$i] ?? ''));
                        if ($value === '') {
                            continue;
                        }
                        $found = true;
                        $gtin = Gtin::normalize($value);
                        $joinLabel = $keys ? implode(',', $keys) : '-';
                        $result[] = [$sku, "$table.$column", $value, $gtin ? "gültig → $gtin  (JOIN $joinLabel)" : 'kein gültiger GTIN'];
                        if ($gtin) {
                            $key = "$table|$joinLabel|$column";
                            $score[$key] = ($score[$key] ?? 0) + 1;
                        }
                    }
                    if ($found) {
                        break; // genaueste Verknüpfung mit Treffer reicht
                    }
                }
            }
        }
        $io->progressFinish();

        $io->section('Werte für die Beispiel-SKUs');
        $io->table(['SKU', 'Tabelle.Spalte', 'Wert', 'Prüfung'], $result);

        // 4. Vorschlag: Tabelle mit den meisten gültigen Treffern, Spalten nach Trefferzahl
        if (!$score) {
            $io->warning('Keine gültigen GTIN/ISBN gefunden. Bitte mit anderen SKUs (z.B. Bücher und Fremdartikel) oder mit --all testen.');
            return Command::SUCCESS;
        }
        $byTable = [];
        foreach ($score as $key => $count) {
            [$table, $join, $column] = explode('|', $key);
            $byTable["$table|$join"]['total'] = ($byTable["$table|$join"]['total'] ?? 0) + $count;
            $byTable["$table|$join"]['columns'][$column] = $count;
        }
        uasort($byTable, fn ($a, $b) => $b['total'] <=> $a['total']);
        $best = array_key_first($byTable);
        [$table, $join] = explode('|', $best);
        $columns = $byTable[$best]['columns'];
        arsort($columns);

        $io->note(sprintf('Meiste Treffer bei den Beispiel-SKUs: %s.%s. Für die Gesamtübersicht: cover:find-gtin --coverage',
            $table, implode('/', array_keys($columns))));
        $this->showConfiguredResult($io, $conn, $skus);

        return Command::SUCCESS;
    }

    /**
     * Zählt über alle Shop-Artikel, wie viele je Tabelle/Spalte eine gültige GTIN/ISBN haben.
     */
    private function coverage(SymfonyStyle $io, Connection $conn, array $candidates, array $joins): int
    {
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM C2WART WHERE SHOP_STATUS = 1');
        $io->section(sprintf('Abdeckung über alle %d Shop-Artikel (C2WART, SHOP_STATUS = 1)', $total));

        $rows = [];
        $results = []; // Tabelle|Join => [Spalte => [sku => true]]
        $platform = $conn->getDatabasePlatform();
        $io->progressStart(array_sum(array_map('count', array_intersect_key($candidates, $joins))));

        foreach ($joins as $table => $options) {
            // genaueste Verknüpfung zuerst; reicht für die Übersicht
            $keys = $options[0];
            $joinLabel = $keys ? implode(',', $keys) : '-';
            $on = $keys
                ? implode(' AND ', array_map(fn ($k) => $k === 'SKU' ? 'TRIM(t.SKU) = TRIM(c.SKU)' : "t.$k = c.$k", $keys))
                : null;

            foreach ($candidates[$table] as $column) {
                $io->progressAdvance();
                $sql = $on
                    ? "SELECT TRIM(c.SKU), TRIM(t.$column) FROM C2WART c JOIN $table t ON $on WHERE c.SHOP_STATUS = 1 AND t.$column IS NOT NULL"
                    : "SELECT TRIM(c.SKU), TRIM(c.$column) FROM C2WART c WHERE c.SHOP_STATUS = 1 AND c.$column IS NOT NULL";
                try {
                    $values = $conn->fetchAllNumeric($platform->modifyLimitQuery($sql, 50000));
                } catch (\Throwable $e) {
                    $rows[] = ["$table.$column", $joinLabel, '-', '-', 'Fehler: ' . mb_substr($e->getMessage(), 0, 60)];
                    continue;
                }
                $filled = [];
                $valid = [];
                $examples = [];
                foreach ($values as [$sku, $value]) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }
                    $filled[$sku] = true;
                    if (($gtin = Gtin::normalize($value)) !== null) {
                        $valid[$sku] = true;
                        if (count($examples) < 3) {
                            $examples[] = "$sku: $value";
                        }
                    }
                }
                $results["$table|$joinLabel"][$column] = $valid;
                $rows[] = ["$table.$column", $joinLabel, count($filled), count($valid), implode("\n", $examples)];
            }
        }
        $io->progressFinish();
        $io->table(['Tabelle.Spalte', 'Verknüpfung', 'Artikel mit Wert', 'davon gültig', 'Beispiele'], $rows);

        $skus = array_map(fn ($v) => trim((string) $v), $conn->fetchFirstColumn('SELECT SKU FROM C2WART WHERE SHOP_STATUS = 1'));
        $this->showConfiguredResult($io, $conn, $skus);

        return Command::SUCCESS;
    }

    /**
     * Zeigt, was die eingestellten Quellen (COVER_GTIN_SOURCES) für die SKUs tatsächlich liefern.
     */
    private function showConfiguredResult(SymfonyStyle $io, Connection $conn, array $skus): void
    {
        $io->section('Ergebnis mit der aktuellen Einstellung (COVER_GTIN_SOURCES)');
        if (!$this->gtinLookup->isEnabled()) {
            $io->warning('GTIN-Ergänzung ist abgeschaltet (COVER_GTIN_SOURCES=0).');
            return;
        }
        $io->listing($this->gtinLookup->describeSources());

        $found = $this->gtinLookup->lookup($skus);
        if ($this->gtinLookup->getLastError() !== null) {
            $io->error('Abfrage fehlgeschlagen: ' . $this->gtinLookup->getLastError());
            return;
        }

        $perGtin = array_count_values($found);
        $duplicates = array_filter($perGtin, fn ($n) => $n > 1);
        $total = count(array_unique($skus));
        $io->success(sprintf('%d von %d Artikeln (%.0f %%) bekommen eine gültige GTIN.', count($found), $total, $total ? 100 * count($found) / $total : 0));

        if ($duplicates) {
            $examples = [];
            foreach (array_slice(array_keys($duplicates), 0, 5) as $gtin) {
                $examples[] = $gtin . ': ' . implode(', ', array_slice(array_keys($found, $gtin, true), 0, 4));
            }
            $io->warning(sprintf('%d GTINs sind mehreren SKUs zugeordnet (z.B. gleiche EAN für verschiedene Varianten). Beispiele:', count($duplicates)));
            $io->listing($examples);
        }
    }

    /** @return string[] */
    private function columns(Connection $conn, string $table): array
    {
        return $this->columnCache[$table] ??= array_map(
            fn ($c) => strtoupper(trim((string) $c)),
            $conn->fetchFirstColumn('SELECT column_name FROM user_tab_columns WHERE table_name = ?', [$table])
        );
    }
}
