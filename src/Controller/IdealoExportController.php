<?php

namespace App\Controller;

use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\Product;
use App\Service\Idealo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * idealo-Angebotsdatei als CSV (UTF-8, Komma-getrennt, RFC 4180).
 * Nutzt dieselben Produkte/Regeln wie der Google-Export des Feeds.
 */
class IdealoExportController extends AbstractController
{
    private const FLUSH_EVERY = 500;
    private const MAX_DESCRIPTION = 1000;

    #[Route('/feed/export/{id}/idealo.csv', name: 'app_feed_export_idealo', methods: ['GET'])]
    public function export(int $id, EntityManagerInterface $em): Response
    {
        $feed = $em->getRepository(FeedConfig::class)->find($id);
        if (!$feed) {
            return new Response('Feed nicht gefunden.', Response::HTTP_NOT_FOUND);
        }

        $carrier = array_key_exists($feed->getIdealoCarrier(), Idealo::CARRIERS) ? $feed->getIdealoCarrier() : 'dhl';
        $payments = array_intersect_key($feed->getIdealoPaymentCosts(), Idealo::PAYMENT_METHODS);
        $settings = [
            // Standard-Lieferzeit: Feed-Einstellung, sonst 3-5 Werktage. Artikelwert aus Cover hat Vorrang.
            'delivery' => $feed->getIdealoDelivery() ?: Idealo::DEFAULT_DELIVERY,
            'deliveryComment' => (string) $feed->getIdealoDeliveryComment(),
            'processingDays' => $feed->getIdealoProcessingDays(),
            'onlyInStock' => $feed->isIdealoOnlyInStock(),
        ];

        $header = ['sku', 'brand', 'title', 'categoryPath', 'url', 'eans', 'description', 'imageUrls', 'price',
            'delivery', 'deliveryCosts_' . $carrier];
        foreach (array_keys($payments) as $method) {
            $header[] = 'paymentCosts_' . $method;
        }
        $header[] = 'fulfillmentType';
        if ($settings['processingDays'] !== null) {
            $header[] = 'maxOrderProcessingTime';
        }
        if ($settings['deliveryComment'] !== '') {
            $header[] = 'deliveryComment';
        }

        $response = new StreamedResponse(function () use ($em, $id, $header, $payments, $settings) {
            @set_time_limit(0);
            $out = fopen('php://output', 'w');
            $this->writeRow($out, $header);

            $query = $em->createQuery(
                'SELECT p FROM App\Entity\Mysql\Product p WHERE p.feed = :feed ORDER BY p.id ASC'
            )->setParameter('feed', $id);

            $count = 0;
            /** @var Product $product */
            foreach ($query->toIterable() as $product) {
                if ($settings['onlyInStock'] && strtolower(trim((string) $product->getAvailability())) !== 'in stock') {
                    continue;
                }

                $images = array_filter(array_merge([(string) $product->getImageLink()], $product->getAdditionalImages() ?? []));

                $row = [
                    $product->getRemoteId(),
                    $product->getBrand() ?? $product->getManufacturer(),
                    $product->getTitle(),
                    $product->getProductType(),
                    $product->getLink(),
                    $product->getGtin(),
                    $this->plainText((string) $product->getDescription()),
                    implode(';', $images),
                    self::money($product->getActivePrice()),
                    Idealo::formatDelivery($product->getDeliveryTime()) ?? $settings['delivery'],
                    self::money((float) $product->getShippingCost()),
                ];
                foreach ($payments as $surcharge) {
                    $row[] = self::money((float) $surcharge);
                }
                $row[] = Idealo::FULFILLMENT_TYPE;
                if ($settings['processingDays'] !== null) {
                    $row[] = (string) $settings['processingDays'];
                }
                if ($settings['deliveryComment'] !== '') {
                    $row[] = $settings['deliveryComment'];
                }

                $this->writeRow($out, $row);

                if ((++$count % self::FLUSH_EVERY) === 0) {
                    fflush($out);
                    flush();
                    $em->clear();
                }
            }

            fclose($out);
            $em->clear();
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'inline; filename="idealo-feed-' . $id . '.csv"');

        return $response;
    }

    /** @param resource $out */
    private function writeRow($out, array $fields): void
    {
        // RFC 4180: Felder in Anführungszeichen, "" als Escape, kein Backslash-Escape
        fputcsv($out, array_map(fn ($v) => (string) $v, $fields), ',', '"', '', "\r\n");
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * idealo: max. 1000 Zeichen reiner Text, ohne HTML.
     */
    private function plainText(string $html): string
    {
        $text = preg_replace('/<\s*(br|\/p|\/li|\/div|\/h\d)\s*\/?>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) > self::MAX_DESCRIPTION) {
            $text = mb_substr($text, 0, self::MAX_DESCRIPTION - 1);
            $cut = mb_strrpos($text, ' ');
            $text = ($cut !== false && $cut > self::MAX_DESCRIPTION - 150 ? mb_substr($text, 0, $cut) : $text) . '…';
        }

        return $text;
    }
}
