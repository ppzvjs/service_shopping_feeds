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

    public function import(): array
    {
        // 1. Feed-URL aus der Datenbank holen
        $config = $this->mysqlEntityManager->getRepository(FeedConfig::class)->findOneBy([]);
        if (!$config || !$config->getFeedUrl()) {
            throw new \Exception('Keine Feed-URL im Dashboard hinterlegt!');
        }
        $feedUrl = $config->getFeedUrl();

        // 2. Blacklist aus der Datenbank laden & aufteilen
        $blacklistEntries = $this->mysqlEntityManager->getRepository(FeedBlacklist::class)->findAll();

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

        // 3. Versandkosten-Regeln & Gratis-Versand-Ausnahmen laden
        $shippingRules = $this->mysqlEntityManager->getRepository(ShippingRule::class)->findBy([], ['minPrice' => 'DESC']);

        $freeShippingEntries = $this->mysqlEntityManager->getRepository(FreeShippingRule::class)->findAll();

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
                $existingProduct = $productRepository->findOneBy(['remoteId' => $remoteId]);
                if ($existingProduct) {
                    $this->mysqlEntityManager->remove($existingProduct);
                    $stats['deleted']++;
                }
                $stats['blacklisted']++;
                continue;
            }

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
                    $shippingPriceRaw = (string) $shippingNamespace->price; // Holt z.B. "5,95 EUR"
                }

                // BOMBENSICHERES PARSEN: Komma durch Punkt ersetzen, alles außer Zahlen und Punkt löschen
                $shippingPriceCleaned = str_replace(',', '.', $shippingPriceRaw);
                $shippingPriceCleaned = preg_replace('/[^0-9.]/', '', $shippingPriceCleaned);
                $finalShippingCost = $shippingPriceCleaned !== '' ? (float)$shippingPriceCleaned : 0.00;
            }

            // ZUERST den Wert ins Objekt schreiben!
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

        if (!empty($currentFeedIds)) {
            $query = $this->mysqlEntityManager->createQuery(
                'DELETE FROM App\Entity\Mysql\Product p WHERE p.remoteId NOT IN (:currentIds)'
            )->setParameter('currentIds', $currentFeedIds);
            $stats['deleted'] += $query->execute();
        }

        return $stats;
    }
}
