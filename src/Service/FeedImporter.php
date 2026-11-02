<?php

namespace App\Service;

use App\Entity\Mysql\FeedBlackList;
use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\FeedOverrideSale;
use App\Entity\Mysql\FeedWhiteList;
use App\Entity\Mysql\FreeShippingRule;
use App\Entity\Mysql\Product;
use App\Entity\Mysql\ShippingRule;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importiert einen Google-Shopping-XML-Feed in die MySQL-Datenbank und wendet dabei
 * die Regeln des Feeds an (Blacklist, Whitelist-Modus, Sale-Override, Preisspanne,
 * Versandkosten).
 *
 * Performance / Robustheit:
 *  - Das XML wird per XMLReader gestreamt statt komplett in den Speicher geladen.
 *  - Bestehende Produkte werden pro Block (BATCH_SIZE) mit EINER Abfrage geladen
 *    statt mit einer Abfrage pro Artikel.
 *  - Der gesamte Import läuft in einer Transaktion: Bricht er ab, bleibt der alte Stand erhalten.
 */
class FeedImporter
{
    private const BATCH_SIZE = 500;
    private const DELETE_CHUNK = 1000;
    private const PARSE_ERROR = 'XML-Datei konnte nicht geladen oder geparst werden.';

    /** Wird nach dem ersten Verbindungsfehler für den Rest des Imports abgeschaltet */
    private bool $coverAvailable = false;
    private bool $coverDeliveryAvailable = false;

    public function __construct(
        private EntityManagerInterface $mysqlEntityManager,
        private CoverGtinLookup $coverGtinLookup,
        private CoverDeliveryTimeLookup $coverDeliveryTimeLookup,
    ) {}

    public function import(int $feedId): array
    {
        // Große Feeds können länger dauern (auch beim Start über die Weboberfläche)
        @set_time_limit(0);

        $em = $this->mysqlEntityManager;

        // 1. Spezifischen Feed aus der Datenbank holen
        $config = $em->getRepository(FeedConfig::class)->find($feedId);
        if (!$config || !$config->getFeedUrl()) {
            throw new \Exception('Der angeforderte Feed existiert nicht oder hat keine URL hinterlegt!');
        }

        // 2. Regeln einmalig laden (als einfache Werte, damit clear() sie nicht beeinflusst)
        $rules = [
            'blacklist' => $this->splitPatterns(array_map(
                fn (FeedBlackList $e) => $e->getSku(),
                $em->getRepository(FeedBlackList::class)->findBy(['feed' => $config])
            )),
            'whitelist' => $this->splitPatterns(array_map(
                fn (FeedWhiteList $e) => $e->getSku(),
                $em->getRepository(FeedWhiteList::class)->findBy(['feed' => $config])
            )),
            'overrideSale' => $this->splitPatterns(array_map(
                fn (FeedOverrideSale $e) => $e->getSku(),
                $em->getRepository(FeedOverrideSale::class)->findBy(['feed' => $config])
            )),
            'freeShipping' => $this->splitPatterns(array_map(
                fn (FreeShippingRule $e) => $e->getSkuPattern(),
                $em->getRepository(FreeShippingRule::class)->findBy(['feed' => $config])
            )),
            'shipping' => array_map(
                fn (ShippingRule $r) => ['minPrice' => $r->getMinPrice(), 'cost' => $r->getShippingCost()],
                $em->getRepository(ShippingRule::class)->findBy(['feed' => $config], ['minPrice' => 'DESC'])
            ),
            'excludeAll' => $config->isExcludeAllProducts(),
            'minPrice' => $config->getMinProductPrice(),
            'maxPrice' => $config->getMaxProductPrice(),
        ];
        $feedUrl = $config->getFeedUrl();

        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'blacklisted' => 0, 'deleted' => 0,
            'withGtin' => 0, 'gtinFromCover' => 0, 'warnings' => []];
        $this->coverAvailable = $this->coverGtinLookup->isEnabled();
        $this->coverDeliveryAvailable = $this->coverDeliveryTimeLookup->isEnabled();
        $currentFeedIds = [];   // remoteId => true (im Feed enthalten und übernommen)
        $excludedIds = [];      // remoteId => true (durch Regeln ausgeschlossen)

        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $batch = [];
            foreach ($this->streamItems($feedUrl) as $item) {
                $batch[] = $item;
                if (count($batch) >= self::BATCH_SIZE) {
                    $this->processBatch($batch, $feedId, $rules, $stats, $currentFeedIds, $excludedIds);
                    $batch = [];
                }
            }
            if ($batch) {
                $this->processBatch($batch, $feedId, $rules, $stats, $currentFeedIds, $excludedIds);
            }

            // --- BEREINIGUNG ---
            // Gelöscht wird, was nicht (mehr) im Feed ist oder durch Regeln ausgeschlossen wurde.
            // Wurde kein einziger Artikel übernommen, werden (wie bisher) nur die
            // ausgeschlossenen Artikel entfernt – Schutz gegen einen leer gelieferten Feed.
            $existingIds = $em->createQuery(
                'SELECT p.remoteId FROM App\Entity\Mysql\Product p WHERE p.feed = :feed'
            )->setParameter('feed', $feedId)->getSingleColumnResult();

            if (!empty($currentFeedIds)) {
                $toDelete = array_filter($existingIds, fn ($id) => !isset($currentFeedIds[$id]));
            } else {
                $toDelete = array_filter($existingIds, fn ($id) => isset($excludedIds[$id]));
            }

            foreach (array_chunk(array_values($toDelete), self::DELETE_CHUNK) as $chunk) {
                $stats['deleted'] += $em->createQuery(
                    'DELETE FROM App\Entity\Mysql\Product p WHERE p.feed = :feed AND p.remoteId IN (:ids)'
                )->setParameter('feed', $feedId)
                    ->setParameter('ids', $chunk)
                    ->execute();
            }

            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $em->clear();
            throw $e instanceof \Exception ? $e : new \Exception($e->getMessage(), 0, $e);
        }

        $em->clear();

        return $stats;
    }

    /**
     * Liest die <item>-Elemente unter <rss><channel> einzeln (Streaming).
     *
     * @return \Generator<\SimpleXMLElement>
     */
    private function streamItems(string $feedUrl): \Generator
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new \XMLReader();
        if (!@$reader->open($feedUrl)) {
            libxml_use_internal_errors($previous);
            throw new \Exception(self::PARSE_ERROR);
        }

        try {
            $sawElement = false;
            while (@$reader->read()) {
                // next() springt nach einem <item> direkt zum Geschwisterknoten -> ggf. direkt weiter
                while ($reader->nodeType === \XMLReader::ELEMENT
                    && $reader->depth === 2
                    && $reader->localName === 'item'
                    && $reader->namespaceURI === '') {
                    $node = @$reader->expand();
                    if ($node === false) {
                        throw new \Exception(self::PARSE_ERROR);
                    }
                    $dom = new \DOMDocument();
                    $imported = $dom->importNode($node, true);
                    $dom->appendChild($imported);
                    yield simplexml_import_dom($imported);

                    if (!@$reader->next()) {
                        break 2;
                    }
                }
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $sawElement = true;
                }
            }

            // Fehler im XML (z.B. abgeschnittene Datei) -> Import abbrechen, Transaktion wird zurückgerollt
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new \Exception(self::PARSE_ERROR);
                }
            }
            if (!$sawElement) {
                throw new \Exception(self::PARSE_ERROR);
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function processBatch(array $items, int $feedId, array $rules, array &$stats, array &$currentFeedIds, array &$excludedIds): void
    {
        $em = $this->mysqlEntityManager;

        // 1. Regeln anwenden, gültige Artikel sammeln
        $accepted = [];
        foreach ($items as $item) {
            $g = $item->children('g', true);
            $remoteId = (string) $g->id;
            if (empty($remoteId)) {
                continue;
            }

            // REGEL 1: Blacklist / REGEL 1b: Whitelist-Modus
            $isExcluded = $this->matches($remoteId, $rules['blacklist']);
            if (!$isExcluded && $rules['excludeAll'] && !$this->matches($remoteId, $rules['whitelist'])) {
                $isExcluded = true;
            }
            if ($isExcluded) {
                $excludedIds[$remoteId] = true;
                $stats['blacklisted']++;
                continue;
            }

            // Preise & Sale-Override
            $price = $this->parsePrice((string) $g->price);
            $salePrice = null;
            if (isset($g->sale_price) && !empty((string) $g->sale_price)) {
                $salePrice = $this->parsePrice((string) $g->sale_price);
            }
            if ($salePrice !== null && $this->matches($remoteId, $rules['overrideSale'])) {
                $price = $salePrice;   // Sale-Preis wird zum normalen Preis
                $salePrice = null;     // Reduzierter Preis im Feed wird entfernt
            }

            // REGEL 1c: Preisspanne (auf den aktiven Preis, wie Product::getActivePrice())
            $activePrice = ($salePrice !== null && $salePrice > 0) ? $salePrice : $price;
            if (($rules['minPrice'] !== null && $activePrice < $rules['minPrice'])
                || ($rules['maxPrice'] !== null && $activePrice > $rules['maxPrice'])) {
                $excludedIds[$remoteId] = true;
                $stats['blacklisted']++;
                continue;
            }

            // REGEL 2: Versandkosten (Gratis-Ausnahme > Preisstaffel > Wert aus dem Feed)
            $shippingCost = null;
            if ($this->matches($remoteId, $rules['freeShipping'])) {
                $shippingCost = 0.00;
            }
            if ($shippingCost === null) {
                foreach ($rules['shipping'] as $rule) {
                    if ($activePrice >= $rule['minPrice']) {
                        $shippingCost = $rule['cost'];
                        break;
                    }
                }
            }
            if ($shippingCost === null) {
                $shippingRaw = '0.00';
                if (isset($g->shipping)) {
                    $shippingRaw = (string) $g->shipping->children('g', true)->price;
                }
                $shippingCost = $this->parsePrice($shippingRaw);
            }

            $additionalImages = [];
            if (isset($g->additional_image_link)) {
                foreach ($g->additional_image_link as $imgLink) {
                    $cleanedLink = trim((string) $imgLink);
                    if (!empty($cleanedLink)) {
                        $additionalImages[] = $cleanedLink;
                    }
                }
            }

            $feedGtin = Gtin::normalize((string) $g->gtin);

            // Doppelte IDs im Feed: der letzte Eintrag gewinnt (vorher: Absturz wegen Unique-Key)
            unset($accepted[$remoteId]);
            $accepted[$remoteId] = [
                'gtin' => $feedGtin,
                'gtinSource' => $feedGtin !== null ? 'feed' : null,
                'mpn' => self::nullIfEmpty((string) $g->mpn),
                'brand' => self::nullIfEmpty((string) $g->brand),
                'googleProductCategory' => self::nullIfEmpty((string) $g->google_product_category),
                'itemGroupId' => self::nullIfEmpty((string) $g->item_group_id),
                'shippingWeight' => self::nullIfEmpty((string) $g->shipping_weight),
                'price' => $price,
                'salePrice' => $salePrice,
                'shippingCost' => $shippingCost,
                'title' => (string) $item->title,
                'link' => (string) $item->link,
                'description' => trim((string) $item->description),
                'manufacturer' => (string) $g->manufacturer,
                'productType' => (string) $g->product_type,
                'availability' => (string) $g->availability,
                'imageLink' => trim((string) $g->image_link),
                'additionalImages' => $additionalImages,
            ];
        }

        if (!$accepted) {
            return;
        }

        $ids = array_map('strval', array_keys($accepted));

        // 2a. Fehlende GTIN/ISBN aus Cover ergänzen (eine Abfrage pro Block)
        $coverChecked = false;
        if ($this->coverAvailable) {
            $missing = array_values(array_filter($ids, fn ($id) => $accepted[$id]['gtin'] === null));
            if ($missing) {
                $found = $this->coverGtinLookup->lookup($missing);
                if ($this->coverGtinLookup->getLastError() !== null) {
                    $this->coverAvailable = false;
                    $stats['warnings'][] = 'GTIN-Ergänzung aus Cover nicht möglich (bisherige Werte bleiben erhalten): '
                        . $this->coverGtinLookup->getLastError();
                } else {
                    $coverChecked = true;
                }
                foreach ($found as $sku => $gtin) {
                    $sku = (string) $sku;
                    if (isset($accepted[$sku])) {
                        $accepted[$sku]['gtin'] = $gtin;
                        $accepted[$sku]['gtinSource'] = 'cover';
                        $stats['gtinFromCover']++;
                    }
                }
            }
        }

        // 2b. Artikelspezifische Lieferzeit aus Cover (C2WART.LIEFER_ZEIT)
        $deliveryTimes = [];
        $deliveryChecked = false;
        if ($this->coverDeliveryAvailable) {
            $deliveryTimes = $this->coverDeliveryTimeLookup->lookup($ids);
            if ($this->coverDeliveryTimeLookup->getLastError() !== null) {
                $this->coverDeliveryAvailable = false;
                $stats['warnings'][] = 'Lieferzeiten aus Cover nicht abrufbar (bisherige Werte bleiben erhalten): '
                    . $this->coverDeliveryTimeLookup->getLastError();
            } else {
                $deliveryChecked = true;
            }
        }

        // 2c. Bestehende Produkte dieses Blocks mit EINER Abfrage laden
        $existing = [];
        $found = $em->createQuery(
            'SELECT p FROM App\Entity\Mysql\Product p WHERE p.feed = :feed AND p.remoteId IN (:ids)'
        )->setParameter('feed', $feedId)
            ->setParameter('ids', $ids)
            ->getResult();
        foreach ($found as $product) {
            $existing[$product->getRemoteId()] = $product;
        }

        $feedRef = $em->getReference(FeedConfig::class, $feedId);

        // 3. Upsert
        foreach ($ids as $remoteId) {
            $data = $accepted[$remoteId];
            $product = $existing[$remoteId] ?? null;
            if ($product) {
                if (!isset($currentFeedIds[$remoteId])) {
                    $stats['updated']++;
                }
            } else {
                $product = new Product();
                $product->setRemoteId($remoteId);
                $stats['inserted']++;
            }

            $product->setFeed($feedRef);
            $product->setPrice($data['price']);
            $product->setSalePrice($data['salePrice']);
            $product->setShippingCost($data['shippingCost']);
            $product->setTitle($data['title']);
            $product->setLink($data['link']);
            $product->setDescription($data['description']);
            $product->setManufacturer($data['manufacturer']);
            $product->setProductType($data['productType']);
            $product->setAvailability($data['availability']);
            $product->setImageLink($data['imageLink']);
            $product->setAdditionalImages($data['additionalImages']);
            if ($data['gtin'] === null && !$coverChecked && $product->getGtinSource() === 'cover') {
                // Cover war diesmal nicht abfragbar: zuletzt aus Cover ergänzte GTIN behalten
                $data['gtin'] = $product->getGtin();
                $data['gtinSource'] = 'cover';
            }
            if ($deliveryChecked) {
                $product->setDeliveryTime($deliveryTimes[$remoteId] ?? null);
            }
            $product->setGtin($data['gtin']);
            $product->setGtinSource($data['gtinSource']);
            $product->setMpn($data['mpn']);
            $product->setBrand($data['brand']);
            $product->setGoogleProductCategory($data['googleProductCategory']);
            $product->setItemGroupId($data['itemGroupId']);
            $product->setShippingWeight($data['shippingWeight']);
            if ($data['gtin'] !== null) {
                $stats['withGtin']++;
            }

            $em->persist($product);
            $currentFeedIds[$remoteId] = true;
            $stats['processed']++;
        }

        $em->flush();
        $em->clear();
    }

    private static function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Teilt SKU-Muster in exakte Werte (als Hash-Set) und Wildcards (mit *).
     */
    private function splitPatterns(array $patterns): array
    {
        $exact = [];
        $wildcards = [];
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if (str_contains($pattern, '*')) {
                $wildcards[] = $pattern;
            } else {
                $exact[$pattern] = true;
            }
        }

        return ['exact' => $exact, 'wildcards' => $wildcards];
    }

    private function matches(string $remoteId, array $patterns): bool
    {
        if (isset($patterns['exact'][$remoteId])) {
            return true;
        }
        foreach ($patterns['wildcards'] as $pattern) {
            if (fnmatch($pattern, $remoteId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wandelt Preisangaben wie "12.99 EUR", "12,99 EUR", "1.234,56 EUR" oder "1,234.56 EUR" in eine Zahl um.
     * (Vorher wurde "12,99 EUR" fälschlich als 1299 gelesen.)
     */
    private function parsePrice(string $raw): float
    {
        $s = (string) preg_replace('/[^0-9,.]/', '', $raw);
        if ($s === '') {
            return 0.0;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // Komma ist Dezimaltrenner, Punkte sind Tausendertrenner
            $s = str_replace(['.', ','], ['', '.'], $s);
        } else {
            // Punkt ist Dezimaltrenner (oder keiner vorhanden), Kommas sind Tausendertrenner
            $s = str_replace(',', '', $s);
        }

        return (float) $s;
    }
}
