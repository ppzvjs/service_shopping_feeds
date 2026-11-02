<?php

namespace App\Controller;

use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class FeedExportController extends AbstractController
{
    private const G_NS = 'http://base.google.com/ns/1.0';
    private const FLUSH_EVERY = 200;

    public function __construct(
        #[Autowire('%app.shop_url%')] private string $shopUrl,
    ) {}

    #[Route('/feed/export/{id}', name: 'app_feed_export', methods: ['GET'])]
    public function export(int $id, EntityManagerInterface $em): Response
    {
        // 1. Prüfen, ob der angeforderte Feed existiert
        $feed = $em->getRepository(FeedConfig::class)->find($id);
        if (!$feed) {
            return new Response('Feed nicht gefunden.', Response::HTTP_NOT_FOUND);
        }
        $feedName = (string) $feed->getName();

        // 2. XML wird direkt gestreamt: Produkte werden einzeln geladen und sofort ausgegeben,
        //    statt alle Produkte + das komplette XML im Speicher zu halten.
        $response = new StreamedResponse(function () use ($em, $id, $feedName) {
            @set_time_limit(0);

            $w = new \XMLWriter();
            $w->openMemory();
            $w->startDocument('1.0', 'utf-8');
            $w->startElement('rss');
            $w->writeAttribute('xmlns:g', self::G_NS);
            $w->writeAttribute('version', '2.0');
            $w->startElement('channel');
            $w->writeElement('title', 'Optimierter Google Shopping Feed - ' . $feedName);
            $w->writeElement('link', $this->shopUrl);
            $w->writeElement('description', 'Generiert durch den Multi-Feed Manager');

            $query = $em->createQuery(
                'SELECT p FROM App\Entity\Mysql\Product p WHERE p.feed = :feed ORDER BY p.id ASC'
            )->setParameter('feed', $id);

            $count = 0;
            /** @var Product $product */
            foreach ($query->toIterable() as $product) {
                $this->writeItem($w, $product);

                if ((++$count % self::FLUSH_EVERY) === 0) {
                    echo $w->outputMemory();
                    flush();
                    $em->clear();
                }
            }

            $w->endElement(); // channel
            $w->endElement(); // rss
            $w->endDocument();
            echo $w->outputMemory();
            flush();
            $em->clear();
        });
        $response->headers->set('Content-Type', 'text/xml; charset=utf-8');

        return $response;
    }

    private function writeItem(\XMLWriter $w, Product $product): void
    {
        $w->startElement('item');

        // Standard RSS Felder
        $w->writeElement('title', (string) $product->getTitle());
        $w->writeElement('link', (string) $product->getLink());

        // Beschreibung als CDATA (wichtig wegen HTML-Inhalten)
        $w->startElement('description');
        $this->writeCdata($w, (string) $product->getDescription());
        $w->endElement();

        // Google Merchant Center Felder
        $this->g($w, 'id', (string) $product->getRemoteId());
        $this->g($w, 'price', number_format((float) $product->getPrice(), 2, '.', '') . ' EUR');

        // Angebotspreis nur ausgeben, wenn er auch existiert
        if ($product->getSalePrice() !== null) {
            $this->g($w, 'sale_price', number_format($product->getSalePrice(), 2, '.', '') . ' EUR');
        }

        $price = $product->getPrice();
        $customLabel0 = match (true) {
            $price < 10   => 'vk0bis10',
            $price < 25   => 'vk10bis25',
            $price < 50   => 'vk25bis50',
            $price < 100  => 'vk50bis100',
            $price < 250  => 'vk100bis250',
            $price < 500  => 'vk250bis500',
            default       => 'vk500bis',
        };
        $this->g($w, 'custom_label_0', $customLabel0);

        // Google erwartet hier "new" (vorher "Neu" -> ungültiger Wert)
        $this->g($w, 'condition', 'new');
        $this->g($w, 'manufacturer', (string) $product->getManufacturer());
        // Marke aus dem Quellfeed, sonst Hersteller
        $this->g($w, 'brand', (string) ($product->getBrand() ?? $product->getManufacturer()));

        // Produktkennzeichen: GTIN (bei Büchern die ISBN-13). Ohne GTIN wird identifier_exists=no gesetzt,
        // weil die MPN im Quellfeed die eigene Artikelnummer ist (keine Herstellernummer).
        if ($product->getGtin() !== null) {
            $this->g($w, 'gtin', $product->getGtin());
        } else {
            $this->g($w, 'identifier_exists', 'no');
        }
        if ($product->getMpn() !== null) {
            $this->g($w, 'mpn', $product->getMpn());
        }
        if ($product->getGoogleProductCategory() !== null) {
            $this->g($w, 'google_product_category', $product->getGoogleProductCategory());
        }
        $this->g($w, 'product_type', (string) $product->getProductType());
        $this->g($w, 'availability', (string) $product->getAvailability());
        $this->g($w, 'image_link', (string) $product->getImageLink());

        // Zusätzliche Bilder
        foreach ($product->getAdditionalImages() ?? [] as $img) {
            $this->g($w, 'additional_image_link', (string) $img);
        }

        if ($product->getItemGroupId() !== null) {
            $this->g($w, 'item_group_id', $product->getItemGroupId());
        }
        if ($product->getShippingWeight() !== null) {
            $this->g($w, 'shipping_weight', $product->getShippingWeight());
        }

        // Modifizierte Versandkosten
        $w->startElement('g:shipping');
        $this->g($w, 'country', 'DE');
        $this->g($w, 'price', number_format((float) $product->getShippingCost(), 2, '.', '') . ' EUR');
        $w->endElement();

        $w->endElement(); // item
    }

    private function g(\XMLWriter $w, string $name, string $value): void
    {
        $w->writeElement('g:' . $name, $value);
    }

    /**
     * CDATA darf kein "]]>" enthalten -> wird in mehrere CDATA-Blöcke aufgeteilt.
     */
    private function writeCdata(\XMLWriter $w, string $text): void
    {
        $w->writeRaw('<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $text) . ']]>');
    }
}
