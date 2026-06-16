<?php
namespace App\Entity\Mysql;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'free_shipping_rule')]
class FreeShippingRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $skuPattern = null; // Hier landet die SKU oder die Wildcard (z.B. BU-30010*)

    public function getId(): ?int { return $this->id; }
    public function getSkuPattern(): ?string { return $this->skuPattern; }
    public function setSkuPattern(string $skuPattern): self { $this->skuPattern = $skuPattern; return $this; }
}
