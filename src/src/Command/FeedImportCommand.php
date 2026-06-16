<?php

namespace App\Command;

use App\Entity\Mysql\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'feed:import',
    description: 'Lädt den Google XML-Feed, speichert/updatet Produkte und löscht abgelaufene Artikel.',
)]
class FeedImportCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $mysqlEntityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Google Feed Parser & DB-Import mit Bereinigung');

        $feedUrl = 'https://pareyshop.de/media/feeds/ppz/google_export_ppz.xml'; // <-- Deine XML-URL
        $feedUrl = 'https://pareyshop.de/media/feeds/ppz/google_export_ppz-sperrgut.xml';
        try {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($feedUrl);

            if ($xml === false) {
                $io->error('XML-Datei konnte nicht geladen werden.');
                return Command::FAILURE;
            }

            $items = $xml->channel->item;
            $totalCount = count($items);
            $io->text(sprintf('Starte Import von %d Produkten...', $totalCount));

            $productRepository = $this->mysqlEntityManager->getRepository(Product::class);

            $processedCount = 0;
            $insertedCount = 0;
            $updatedCount = 0;

            // Hier drin sammeln wir ALLE gültigen IDs aus dem aktuellen Feed-Durchlauf
            $currentFeedIds = [];

            foreach ($items as $item) {
                $googleNamespace = $item->children('g', true);

                $remoteId = (string) $googleNamespace->id;
                if (empty($remoteId)) {
                    continue;
                }

                // ID für die spätere Bereinigung merken
                $currentFeedIds[] = $remoteId;

                // Upsert-Logik
                $product = $productRepository->findOneBy(['remoteId' => $remoteId]);

                if ($product) {
                    $updatedCount++;
                } else {
                    $product = new Product();
                    $product->setRemoteId($remoteId);
                    $insertedCount++;
                }

                // --- REGLER PREIS PARSEN ---
                $priceRaw = (string) $googleNamespace->price;
                $price = (float) filter_var($priceRaw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                $product->setPrice($price);

// --- ANGEBOTSPREIS PARSEN (NEU) ---
                if (isset($googleNamespace->sale_price) && !empty((string)$googleNamespace->sale_price)) {
                    $salePriceRaw = (string) $googleNamespace->sale_price;
                    $salePrice = (float) filter_var($salePriceRaw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $product->setSalePrice($salePrice);
                } else {
                    $product->setSalePrice(null); // Wichtig, falls ein Angebot abgelaufen ist!
                }





                $shippingPriceRaw = '0.00';
                if (isset($googleNamespace->shipping)) {
                    $shippingNamespace = $googleNamespace->shipping->children('g', true);
                    $shippingPriceRaw = (string) $shippingNamespace->price;
                }
                $shippingPriceReady = str_replace(',', '.', $shippingPriceRaw);
                $shippingCost = (float) filter_var($shippingPriceReady, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                // Entity befüllen
                $product->setTitle((string) $item->title);
                $product->setLink((string) $item->link);
                $product->setDescription(trim((string) $item->description));
                $product->setManufacturer((string) $googleNamespace->manufacturer);
                $product->setProductType((string) $googleNamespace->product_type);
                $product->setPrice($price);
                $product->setShippingCost($shippingCost);
                $product->setAvailability((string) $googleNamespace->availability);

                // Bilder verarbeiten
                $product->setImageLink(trim((string) $googleNamespace->image_link));
                $additionalImages = [];
                if (isset($googleNamespace->additional_image_link)) {
                    foreach ($googleNamespace->additional_image_link as $imgLink) {
                        $cleanedLink = trim((string) $imgLink);
                        if (!empty($cleanedLink)) {
                            $additionalImages[] = $cleanedLink;
                        }
                    }
                }
                $product->setAdditionalImages($additionalImages);

                $this->mysqlEntityManager->persist($product);
                $processedCount++;

                // Batch-Processing (alle 50)
                if (($processedCount % 50) === 0) {
                    $this->mysqlEntityManager->flush();
                    $this->mysqlEntityManager->clear();
                    $productRepository = $this->mysqlEntityManager->getRepository(Product::class);
                }
            }

            // Letzten Batch wegschreiben
            $this->mysqlEntityManager->flush();
            $this->mysqlEntityManager->clear();


            // =========================================================================
            // SCHRITT 4: BEREINIGUNG (Alte Artikel entfernen)
            // =========================================================================
            $io->text('Prüfe auf alte Produkte, die nicht mehr im Feed existieren...');

            $deletedCount = 0;
            if (!empty($currentFeedIds)) {
                // Wir nutzen eine DQL (Doctrine Query Language) Query für maximale Performance.
                // Lösche alle Produkte aus der DB, deren remoteId NICHT im Array $currentFeedIds vorkommt.
                $query = $this->mysqlEntityManager->createQuery(
                    'DELETE FROM App\Entity\Mysql\Product p WHERE p.remoteId NOT IN (:currentIds)'
                )->setParameter('currentIds', $currentFeedIds);

                $deletedCount = $query->execute();
            }
            // =========================================================================


            // Finale Ausgabe
            $io->section('Import- & Bereinigungs-Statistik:');
            $io->listing([
                sprintf('Im aktuellen Feed verarbeitet: %d', $processedCount),
                sprintf('-> Davon neu angelegt: %d', $insertedCount),
                sprintf('-> Davon aktualisiert: %d', $updatedCount),
                sprintf('Aus Datenbank gelöscht (da nicht mehr im Feed): %d', $deletedCount),
            ]);

            $io->success('Import-Prozess komplett abgeschlossen!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Kritischer Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
