<?php

namespace App\Service;

use App\Entity\Mysql\Products;
use Doctrine\ORM\EntityManagerInterface;

class FeedMapperService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /**
     * @param string[] $blacklistedSkus Array aus Artikelnummern, die gelöscht werden sollen
     */
    public function process(array $blacklistedSkus = []): array
    {
        $repository = $this->entityManager->getRepository(Products::class);
        $products = $repository->findAll();

        $stats = ['total' => count($products), 'removed' => 0, 'updated' => 0];

        foreach ($products as $product) {
            // REGEL 1: Artikelnummer steht auf der Blacklist aus dem Frontend
            if (in_array($product->getRemoteId(), $blacklistedSkus, true)) {
                $this->entityManager->remove($product);
                $stats['removed']++;
                continue;
            }

            // REGEL 2: Versandkosten anpassen
            $newShippingCost = ($product->getPrice() > 50) ? 0.00 : 4.90;
            if ($product->getShippingCost() !== $newShippingCost) {
                $product->setShippingCost($newShippingCost);
                $stats['updated']++;
            }
        }

        $this->entityManager->flush();

        return $stats;
    }
}
