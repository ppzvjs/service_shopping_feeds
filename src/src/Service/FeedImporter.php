<?php

namespace App\Service;

use App\Entity\Mysql\Product;
use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\FeedBlacklist;
use App\Entity\Mysql\ShippingRule;
use App\Entity\Mysql\FreeShippingRule;
use Doctrine\ORM\EntityManagerInterface;

class FeedImporter
{
    public function __construct(
        private EntityManagerInterface $mysqlEntityManager
    ) {}

    public function import(int $feedId): array
    {
        // 1. Spezifischen Feed aus der Datenbank holen
        $config = $this->mysqlEntityManager->getRepository(FeedConfig::class)->find($feedId);
        if (!$config || !$config->getFeedUrl()) {
            throw new \Exception('Der angeforderte Feed existiert nicht oder hat keine URL hinterlegt!');
        }
        $feedUrl = $config->getFeedUrl();

        // 2. Blacklist gefiltert nach diesem Feed laden & aufteilen
        $blacklistEntries = $this->mysqlEntityManager->getRepository(FeedBlacklist::class)->findBy(['feed' => $config]);

        $exactBlacklist = [];
        $wildcardBlacklist = [];

        foreach ($blacklistEntries as $entry) {
            $skuPattern = trim($entry->getSku());
            if ($skuPattern === '') {
                continue;
            }
            if (str_contains($skuPattern, '*')) {
                $wildcardBlacklist[] = $skuPattern;
            } else {
                $exactBlacklist[] = $skuPattern;
            }
        }

        // 3. Versandkosten-Regeln & Gratis-Versand-Ausnahmen gefiltert nach diesem Feed laden
        $shippingRules = $this->mysqlEntityManager->getRepository(ShippingRule::class)->findBy(['feed' => $config], ['minPrice' => 'DESC']);

        $freeShippingEntries = $this->mysqlEntityManager->getRepository(FreeShippingRule::class)->findBy(['feed' => $config]);

        $exactFreeShipping = [];
        $wildcardFreeShipping = [];

        foreach ($freeShippingEntries as $entry) {
            $pattern = trim($entry->getSkuPattern());
            if ($pattern === '') {
                continue;
            }
            if (str_contains($pattern, '*')) {
                $wildcardFreeShipping[] = $pattern;
            } else {
                $exactFreeShipping[] = $pattern;
            }
        }

        // 4. XML laden
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
            $isBlacklisted = false;

            if (in_array($remoteId, $exactBlacklist, true)) {
                $isBlacklisted = true;
            } else {
                foreach ($wildcardBlacklist as $pattern) {
                    if (fnmatch($pattern, $remoteId)) {
                        $isBlacklisted = true;
                        break;
                    }
                }
            }

            if ($isBlacklisted) {
                // Falls das Produkt früher mal in diesem Feed importiert wurde -> löschen
                $existingProduct = $productRepository->findOneBy(['remoteId' => $remoteId, 'feed' => $config]);
                if ($existingProduct) {
                    $this->mysqlEntityManager->remove($existingProduct);
                    $stats['deleted']++;
                }
                $stats['blacklisted']++;
                continue;
            }

            $currentFeedIds[] = $remoteId;

            // --- UPSERT-LOGIK (FÜR MULTI-FEED KORRIGIERT) ---
            $product = $productRepository->findOneBy(['remoteId' => $remoteId, 'feed' => $config]);
            if ($product) {
                $stats['updated']++;
            } else {
                $product = new Product();
                $product->setRemoteId($remoteId);
                $stats['inserted']++;
            }

            // WICHTIG: Die Feed-Zuweisung MUSS immer stattfinden, um NULL-Exceptions zu verhindern
            $product->setFeed($config);

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
            $activePrice = $product->getActivePrice();
            $finalShippingCost = null;

            // Schritt A1: Exakter Abgleich für Gratis-Versand
            if (in_array($remoteId, $exactFreeShipping, true)) {
                $finalShippingCost = 0.00;
            }

            // Schritt A2: Wildcard-Abgleich für Gratis-Versand
            if ($finalShippingCost === null && !empty($wildcardFreeShipping)) {
                foreach ($wildcardFreeShipping as $pattern) {
                    if (fnmatch($pattern, $remoteId)) {
                        $finalShippingCost = 0.00;
                        break;
                    }
                }
            }

            // Schritt B: Preis-Staffeln prüfen
            if ($finalShippingCost === null) {
                foreach ($shippingRules as $rule) {
                    if ($activePrice >= $rule->getMinPrice()) {
                        $finalShippingCost = $rule->getShippingCost();
                        break;
                    }
                }
            }

            // Schritt C: Fallback auf originalen Feed (Komma-gesichert!)
            if ($finalShippingCost === null) {
                $shippingPriceRaw = '0.00';
                if (isset($googleNamespace->shipping)) {
                    $shippingNamespace = $googleNamespace->shipping->children('g', true);
                    $shippingPriceRaw = (string) $shippingNamespace->price;
                }

                $shippingPriceCleaned = str_replace(',', '.', $shippingPriceRaw);
                $shippingPriceCleaned = preg_replace('/[^0-9.]/', '', $shippingPriceCleaned);
                $finalShippingCost = $shippingPriceCleaned !== '' ? (float)$shippingPriceCleaned : 0.00;
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
                // Nach dem clear() müssen wir das Repository und die Config neu an den EM binden
                $productRepository = $this->mysqlEntityManager->getRepository(Product::class);
                $config = $this->mysqlEntityManager->getRepository(FeedConfig::class)->find($feedId);
            }
        }

        $this->mysqlEntityManager->flush();
        $this->mysqlEntityManager->clear();

        // --- BEREINIGUNG: Nur alte Artikel DIESES Feeds löschen ---
        if (!empty($currentFeedIds)) {
            // Re-bind der Config für das DQL-Query nach dem letzten clear()
            $config = $this->mysqlEntityManager->getRepository(FeedConfig::class)->find($feedId);

            $query = $this->mysqlEntityManager->createQuery(
                'DELETE FROM App\Entity\Mysql\Product p WHERE p.feed = :feed AND p.remoteId NOT IN (:currentIds)'
            )->setParameter('feed', $config)
                ->setParameter('currentIds', $currentFeedIds);
            $stats['deleted'] += $query->execute();
        }

        return $stats;
    }
}
