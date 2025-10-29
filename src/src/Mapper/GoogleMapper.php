<?php

namespace App\Mapper;

use App\Entity\Cover\Products;

class GoogleMapper
{
    private \SimpleXMLElement $xml;
    private \SimpleXMLElement $channel;

    public function __construct()
    {
        // Proper header for Google Merchant feed
        $this->xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><rss xmlns:g="http://base.google.com/ns/1.0" version="2.0"></rss>'
        );

        $this->channel = $this->xml->addChild('channel');
        $this->channel->addChild('title', 'Pareyshop Product Feed');
        $this->channel->addChild('link', 'https://pareyshop.de');
        $this->channel->addChild('description', 'Google Shopping Feed for Pareyshop');
    }

    public function addProduct(Products $product):void{
        $item = $this->channel->addChild('item');
        $item->addChild('g:id',$product->getArtikelNr(), 'http://base.google.com/ns/1.0');
        $item->addChild('g:title',htmlspecialchars($product->getTitle()), 'http://base.google.com/ns/1.0');
        $description = $product->getDescription() ?? '';
        $description = preg_replace('/\s+/', ' ', $description); // collapse whitespace
        $description = trim($description);

        // Create <g:description> with CDATA (no escaping)
        $descNode = dom_import_simplexml(
            $item->addChild('g:description', null, 'http://base.google.com/ns/1.0')
        );
        $owner = $descNode->ownerDocument;
        $descNode->appendChild($owner->createCDATASection($description));
        $item->addChild('link', 'https://pareyshop.de/' . $product->getLink());
    }

    public function addProductOld(Products $product): void
    {
        $item = $this->channel->addChild('item');

        $meta = $product->getProductsMetas()->first() ?: null;
        $c2wart = $product->getProductsC2Warts()->first() ?: null;
        $buplist = $product->getProductsBuplists()->first() ?: null;

        // --- Basic data ---
        $sku = $c2wart?->getSku() ?? '';
        $title = $product->getTitle() ?? '';
        $desc = $c2wart?->getSeo() ?? '';
        $link = $c2wart?->getArtikelUrlWs() ?? '';
        $price = $buplist?->getPreiskat() ?? '0.00';
        $image = 'https://pareyshop.de/media/catalog/product/default.jpg';
        $manufacturer = 'Pario Print';
        $productType = 'Jagen &gt; DJZ Edition &gt; Allerlei/Sonstiges/Verschiedenes';
        $shippingWeight = '0.000000 kg';

        // --- Add <g:...> elements ---
        $item->addChild('g:id', $sku, 'http://base.google.com/ns/1.0');
        $item->addChild('title', htmlspecialchars($title));

        // CDATA for description
        $descNode = dom_import_simplexml($item->addChild('description'));
        $owner = $descNode->ownerDocument;
        $descNode->appendChild($owner->createCDATASection($desc));

        $item->addChild('g:manufacturer', $manufacturer, 'http://base.google.com/ns/1.0');
        $item->addChild('g:google_product_category', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:product_type', $productType, 'http://base.google.com/ns/1.0');
        $item->addChild('link', $link);
        $item->addChild('g:image_link', $image, 'http://base.google.com/ns/1.0');
        $item->addChild('g:additional_image_link', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:condition', 'new', 'http://base.google.com/ns/1.0');
        $item->addChild('g:availability', 'in stock', 'http://base.google.com/ns/1.0');
        $item->addChild('g:price', sprintf('%.2f EUR', (float) $price), 'http://base.google.com/ns/1.0');
        $item->addChild('g:brand', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:gtin', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:mpn', $sku, 'http://base.google.com/ns/1.0');
        $item->addChild('g:identifier_exists', 'TRUE', 'http://base.google.com/ns/1.0');
        $item->addChild('g:gender', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:age_group', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:size', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:item_group_id', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:color', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:is_bundle', 'false', 'http://base.google.com/ns/1.0');
        $item->addChild('g:material', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:pattern', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:shipping_weight', $shippingWeight, 'http://base.google.com/ns/1.0');
        $item->addChild('g:tax', '', 'http://base.google.com/ns/1.0');

        // --- Shipping block ---
        $shipping = $item->addChild('g:shipping', null, 'http://base.google.com/ns/1.0');
        $shipping->addChild('g:country', 'DE', 'http://base.google.com/ns/1.0');
        $shipping->addChild('g:region', '', 'http://base.google.com/ns/1.0');
        $shipping->addChild('g:service', '', 'http://base.google.com/ns/1.0');
        $shipping->addChild('g:price', '5,95 EUR', 'http://base.google.com/ns/1.0');

        // --- Optional fields ---
        $item->addChild('g:multipack', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:adult', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:adwords_grouping', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:adwords_labels', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:adwords_redirect', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:unit_pricing_measure', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:unit_pricing_base_measure', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:energy_efficiency_class', '', 'http://base.google.com/ns/1.0');
        $item->addChild('g:online_only', '', 'http://base.google.com/ns/1.0');
    }

    public function getFeed(): string
    {
        return $this->xml->asXML();
    }
}
