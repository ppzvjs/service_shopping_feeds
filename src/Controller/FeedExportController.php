<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\Product;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FeedExportController extends AbstractController
{
    #[Route('/feed/export/{id}', name: 'app_feed_export', methods: ['GET'])]
    public function export(int $id, EntityManagerInterface $em): Response
    {
        // 1. Prüfen, ob der angeforderte Feed existiert
        $feed = $em->getRepository(FeedConfig::class)->find($id);
        if (!$feed) {
            return new Response('Feed nicht gefunden.', Response::HTTP_NOT_FOUND);
        }

        // 2. Alle Produkte laden, die zu diesem Feed gehören
        $products = $em->getRepository(Product::class)->findBy(['feed' => $feed]);

        // 3. XML-Struktur initialisieren
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"/>');
        $channel = $xml->addChild('channel');
        $channel->addChild('title', 'Optimierter Google Shopping Feed - ' . $feed->getName());
        $channel->addChild('link', $this->getParameter('kernel.project_dir')); // Oder deine feste Shop-URL
        $channel->addChild('description', 'Generiert durch den Multi-Feed Manager');

        // 4. Produkte in das XML mappen
        foreach ($products as $product) {
            $item = $channel->addChild('item');

            // Standard RSS Felder
            $item->addChild('title', htmlspecialchars($product->getTitle(), ENT_XML1, 'UTF-8'));
            $item->addChild('link', htmlspecialchars($product->getLink(), ENT_XML1, 'UTF-8'));

            // Beschreibung als CDATA einbinden (wichtig wegen HTML-Inhalten)
            $descNode = $item->addChild('description');
            $descNodeDoc = dom_import_simplexml($descNode);
            $descNodeOwner = $descNodeDoc->ownerDocument;
            $descNodeDoc->appendChild($descNodeOwner->createCDATASection($product->getDescription()));

            // Google Merchant Center Felder (mit dem Präfix 'g:')
            $item->addChild('g:id', $product->getRemoteId(), 'http://base.google.com/ns/1.0');
            $item->addChild('g:price', number_format($product->getPrice(), 2, '.', '') . ' EUR', 'http://base.google.com/ns/1.0');

            // Angebotspreis nur ausgeben, wenn er auch existiert
            if ($product->getSalePrice() !== null) {
                $item->addChild('g:sale_price', number_format($product->getSalePrice(), 2, '.', '') . ' EUR', 'http://base.google.com/ns/1.0');
            }

            $price = $product->getPrice();
            $customLabel0 = match (true) {
                $price < 10                => 'vk0bis10',
                $price >= 10  && $price < 25  => 'vk10bis25',
                $price >= 25  && $price < 50  => 'vk25bis50',
                $price >= 50  && $price < 100 => 'vk50bis100',
                $price >= 100 && $price < 250 => 'vk100bis250',
                $price >= 250 && $price < 500 => 'vk250bis500',
                $price >= 500               => 'vk500bis',
                default                     => null,
            };

            if ($customLabel0 !== null) {
                $item->addChild('g:custom_label_0', $customLabel0, 'http://base.google.com/ns/1.0');
            }

            $item->addChild('g:condition',htmlspecialchars('Neu', ENT_XML1, 'UTF-8'), 'http://base.google.com/ns/1.0');
            $item->addChild('g:manufacturer', htmlspecialchars($product->getManufacturer(), ENT_XML1, 'UTF-8'), 'http://base.google.com/ns/1.0');
            $item->addChild('g:brand', htmlspecialchars($product->getManufacturer(), ENT_XML1, 'UTF-8'), 'http://base.google.com/ns/1.0');
            $item->addChild('g:product_type', htmlspecialchars($product->getProductType(), ENT_XML1, 'UTF-8'), 'http://base.google.com/ns/1.0');
            $item->addChild('g:availability', $product->getAvailability(), 'http://base.google.com/ns/1.0');
            $item->addChild('g:image_link', htmlspecialchars($product->getImageLink(), ENT_XML1, 'UTF-8'), 'http://base.google.com/ns/1.0');

            // Zusätzliche Bilder exportieren
            if (!empty($product->getAdditionalImages())) {
                foreach ($product->getAdditionalImages() as $img) {
                    $item->addChild('g:additional_image_link', htmlspecialchars($img, ENT_XML1, 'UTF-8'), 'http://base.google.com/ns/1.0');
                }
            }

            // Modifizierte Versandkosten ausgeben
            $shipping = $item->addChild('g:shipping', null, 'http://base.google.com/ns/1.0');
            $shipping->addChild('g:country', 'DE', 'http://base.google.com/ns/1.0');
            $shipping->addChild('g:price', number_format($product->getShippingCost(), 2, '.', '') . ' EUR', 'http://base.google.com/ns/1.0');
        }

        // 5. XML als Response mit richtigem Content-Type zurückgeben
        $response = new Response($xml->asXML());
        $response->headers->set('Content-Type', 'text/xml; charset=utf-8');

        return $response;
    }
}
