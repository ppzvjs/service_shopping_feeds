<?php
namespace App\Entity\Mysql;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shipping_rule')]
class ShippingRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $minPrice = '0.00'; // Ab welchem Produktpreis gilt das?

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $shippingCost = '0.00'; // Was soll es dann kosten?

    #[ORM\ManyToOne(targetEntity: FeedConfig::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FeedConfig $feed = null;

    public function getId(): ?int { return $this->id; }

    public function getMinPrice(): float { return (float)$this->minPrice; }
    public function setMinPrice(float $minPrice): self { $this->minPrice = (string)$minPrice; return $this; }

    public function getShippingCost(): float { return (float)$this->shippingCost; }
    public function setShippingCost(float $shippingCost): self { $this->shippingCost = (string)$shippingCost; return $this; }

    public function getFeed(): ?FeedConfig { return $this->feed; }
    public function setFeed(?FeedConfig $feed): self { $this->feed = $feed; return $this; }
}
