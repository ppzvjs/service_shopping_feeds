<?php

namespace App\Command;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'abo:paypal',
    description: 'Add a short description for your command',
)]
class AboPaypalCommand extends Command
{
    private ManagerRegistry $registry;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct();
        $this->registry = $registry;
    }



    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Abo Paypal Command');
        $abos = $this->getAllAbos();
        $tableData = [];
        foreach($abos as $k => $abo){
            if($k >= 500){
                continue;
            }
            $row = [
                'BEST_NR' => trim($abo['BEST_NR']),
                'COUNT_ROWS' => $abo['COUNT_ROWS']
            ];
            $details = $this->getDetailsOrder($abo['BEST_NR']);
            usort($details, function ($a, $b) {
                // Kategorie bestimmen
                $catA = $this->getCategory(trim($a['PREIS_KAT']));
                $catB = $this->getCategory(trim($b['PREIS_KAT']));

                // Kategorie-Vergleich (A < B < C)
                if ($catA !== $catB) {
                    return $catA <=> $catB;
                }

                // Innerhalb der gleichen Kategorie nach OBJ_NR sortieren
                if ($a['OBJ_NR'] != $b['OBJ_NR']) {
                    return $a['OBJ_NR'] <=> $b['OBJ_NR'];
                }

                // Falls gleiche OBJ_NR: LIST_NR DESC
                return (int)$b['LIST_NR'] <=> (int)$a['LIST_NR'];
            });

            foreach($details as $key => $detail){
                $row['ADR_NR'] = $detail['ADR_NR'];
                $row['PREIS_KAT_' . $key] = trim($detail['PREIS_KAT']);
                $row['OBJ_NR_' . $key] = trim($detail['OBJ_NR']);
                $row['LIST_NR_' . $key] = trim($detail['LIST_NR']);
                $row['FAKT_ZR_' . $key] = trim($detail['FAKT_ZR']);
                $row['PREIS_M1_'. $key] = trim($detail['PREIS_M1']);
            }
            $tableData[] = $row;
            $io->section('Abo '.$abo['BEST_NR'].' ('.$abo['COUNT_ROWS'].' rows)');
        }
        // --- Prepare CSV file path --------------------------------------------
    $csvPath = sprintf('%s/abo_paypal_%s.csv',
        '/opt/feed/',
        date('Ymd_His')
    );

$file = fopen($csvPath, 'w');

// --- Build dynamic header (merge keys from all rows) -------------------------------------
$allHeaders = [];

foreach ($tableData as $row) {
    foreach (array_keys($row) as $key) {
        $allHeaders[$key] = true;   // use value true to avoid duplicates
    }
}

$headers = array_keys($allHeaders);
sort($headers); // optional: alphabetical order
fputcsv($file, $headers, ';');

// --- Write Rows --------------------------------------------------------
foreach ($tableData as $row) {
    // ensure missing fields are empty
    $line = [];
    foreach ($headers as $header) {
        $line[] = $row[$header] ?? '';   // empty if not exists
    }
    fputcsv($file, $line, ';');
}

fclose($file);
        return Command::SUCCESS;
    }

    private function getAllAbos(){
        $sql = "

SELECT a.best_nr, COUNT(*) AS count_rows
FROM ABOSTAMM a
WHERE REGEXP_LIKE(a.best_nr, '^([0-9]+)_([0-9]+)-') AND a.K_DATUM IS NULL AND a.Z_BED = '93'
GROUP BY a.best_nr
HAVING COUNT(*) >= 2";
        $connection = $this->registry->getConnection('cover');
        $stmt = $connection->prepare($sql);
        $resultSet = $stmt->executeQuery();
        $results = $resultSet->fetchAllAssociative();
        return $results;
    }

    private function getDetailsOrder(string $orderNumber){
        $sql = "SELECT
    ADR_NR,
    PREIS_KAT,
    BEST_NR,
    OBJ_NR,
    LIST_NR,
    FAKT_ZR,
    PREIS_M1,
    RN
FROM (
    SELECT
        a.ADR_NR,
        a.PREIS_KAT,
        a.BEST_NR,
        m.OBJ_NR AS OBJ_NR,
        m.LIST_NR AS LIST_NR,
        m.FAKT_ZR AS FAKT_ZR,
        m.PREIS_M1 AS PREIS_M1,
        ROW_NUMBER() OVER (
            PARTITION BY m.OBJ_NR
            ORDER BY TO_NUMBER(m.LIST_NR) DESC
        ) AS RN
    FROM ABOSTAMM a
    LEFT JOIN MOBPLIST m
        ON a.OBJ_NR = m.OBJ_NR
       AND a.PREIS_KAT = m.PREIS_KAT
       AND a.FAKT_ZR = m.FAKT_ZR
    WHERE a.BEST_NR = '" . $orderNumber . "'
)
WHERE RN = 1
";
        $connection = $this->registry->getConnection('cover');
        $stmt = $connection->prepare($sql);
        $resultSet = $stmt->executeQuery();
        $results = $resultSet->fetchAllAssociative();
        return $results;
    }

    private function getCategory(string $preisKat): int
    {
        if (preg_match('/^[0-9]{2}$/', $preisKat)) {
            return 1;   // Kategorie A
        }
        if (preg_match('/^[0-9]{2}e$/', $preisKat)) {
            return 2;   // Kategorie B
        }
        return 3;       // Kategorie C (VJP, Digital, Sonderfälle)
    }

}
