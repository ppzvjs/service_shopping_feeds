<?php

namespace App\Service;

use App\Entity\Mysql\Product;
use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\FeedBlackList;
use App\Entity\Mysql\ShippingRule;
use Doctrine\ORM\EntityManagerInterface;

class FeedImporter
{
    public function __construct(
        private EntityManagerInterface $mysqlEntityManager
    ) {}

    public function import(): array
    {
        // 1. Feed-URL aus der Datenbank holen
        $config = $this->mysqlEntityManager->getRepository(FeedConfig::class)->findOneBy([]);
        if (!$config || !$config->getFeedUrl()) {
            throw new \Exception('Keine Feed-URL im Dashboard hinterlegt!');
        }
        $feedUrl = $config->getFeedUrl();

        // 2. Blacklist & Versandregeln aus der Datenbank laden
        $blacklistEntries = $this->mysqlEntityManager->getRepository(FeedBlackList::class)->findAll();
        $blacklistedSkus = array_map(fn($item) => $item->getSku(), $blacklistEntries);

        $shippingRules = $this->mysqlEntityManager->getRepository(ShippingRule::class)->findBy([], ['minPrice' => 'DESC']);

        // 3. XML laden
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($feedUrl);
        if ($xml === false) {
            throw new \Exception('XML-Datei konnte nicht geladen oder geparst werden.');
        }

        $items = $xml->channel->item;
        $productRepository = $this->mysqlEntityManager->getRepository(Product::class);

        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'blacklisted' => 0, 'deleted' => 0];
        $currentFeedIds = [];

        foreach ($items as $item) {
            $googleNamespace = $item->children('g', true);
            $remoteId = (string) $googleNamespace->id;

            if (empty($remoteId)) {
                continue;
            }

            // --- REGEL 1: BLACKLIST CHECK ---
            if (in_array($remoteId, $blacklistedSkus, true)) {
                // Falls das Produkt früher mal importiert wurde, jetzt aber auf der Blacklist steht -> aus DB löschen
                $existingProduct = $productRepository->findOneBy(['remoteId' => $remoteId]);
                if ($existingProduct) {
                    $this->mysqlEntityManager->remove($existingProduct);
                    $stats['deleted']++;
                }
                $stats['blacklisted']++;
                continue; // Überspringen, nicht speichern!
            }

            // Gütige ID für die spätere Bereinigung am Ende merken
            $currentFeedIds[] = $remoteId;

            // Upsert-Logik
            $product = $productRepository->findOneBy(['remoteId' => $remoteId]);
            if ($product) {
                $stats['updated']++;
            } else {
                $product = new Product();
                $product->setRemoteId($remoteId);
                $stats['inserted']++;
            }

            // Preise parsen
            $priceRaw = (string) $googleNamespace->price;
            $price = (float) filter_var($priceRaw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $product->setPrice($price);

            if (isset($googleNamespace->sale_price) && !empty((string)$googleNamespace->sale_price)) {
                $salePriceRaw = (string) $googleNamespace->sale_price;
                $salePrice = (float) filter_var($salePriceRaw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                $product->setSalePrice($salePrice);
            } else {
                $product->setSalePrice(null);
            }

            // --- REGEL 2: VERSANDKOSTEN ANPASSEN ---
            // Wir nehmen den aktuell gültigen Preis (Angebot oder Normalpreis) als Basis
            $activePrice = $product->getActivePrice();
            $finalShippingCost = null;

            // Wir gehen die Regeln durch (da nach minPrice DESC sortiert, greift die höchste passende Regel zuerst)
            foreach ($shippingRules as $rule) {
                if ($activePrice >= $rule->getMinPrice()) {
                    $finalShippingCost = $rule->getShippingCost();
                    break; // Passende Regel gefunden, Schleife abbrechen
                }
            }

            // Falls KEINE Regel zutrifft, nehmen wir die originalen Versandkosten aus dem Feed
            if ($finalShippingCost === null) {
                $shippingPriceRaw = '0.00';
                if (isset($googleNamespace->shipping)) {
                    $shippingNamespace = $googleNamespace->shipping->children('g', true);
                    $shippingPriceRaw = (string) $shippingNamespace->price;
                }
                $shippingPriceReady = str_replace(',', '.', $shippingPriceRaw);
                $finalShippingCost = (float) filter_var($shippingPriceReady, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            }
            $product->setShippingCost($finalShippingCost);

            // Restliche Standarddaten befüllen
            $product->setTitle((string) $item->title);
            $product->setLink((string) $item->link);
            $product->setDescription(trim((string) $item->description));
            $product->setManufacturer((string) $googleNamespace->manufacturer);
            $product->setProductType((string) $googleNamespace->product_type);
            $product->setAvailability((string) $googleNamespace->availability);
            $product->setImageLink(trim((string) $googleNamespace->image_link));

            // Zusätzliche Bilder
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
            $stats['processed']++;

            // Batch-Processing
            if (($stats['processed'] % 50) === 0) {
                $this->mysqlEntityManager->flush();
                $this->mysqlEntityManager->clear();
                $productRepository = $this->mysqlEntityManager->getRepository(Product::class);
            }
        }

        $this->mysqlEntityManager->flush();
        $this->mysqlEntityManager->clear();

        // --- BEREINIGUNG: Artikel löschen, die gar nicht mehr im Feed sind ---
        if (!empty($currentFeedIds)) {
            $query = $this->mysqlEntityManager->createQuery(
                'DELETE FROM App\Entity\Mysql\Product p WHERE p.remoteId NOT IN (:currentIds)'
            )->setParameter('currentIds', $currentFeedIds);
            $stats['deleted'] += $query->execute();
        }

        return $stats;
    }
}
