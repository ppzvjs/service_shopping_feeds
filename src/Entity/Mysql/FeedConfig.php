<?php

// src/Entity/Mysql/FeedConfig.php
namespace App\Entity\Mysql;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'feed_config')]
class FeedConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null; // NEU: Zur Unterscheidung im UI

    #[ORM\Column(type: 'text')]
    private ?string $feedUrl = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $minProductPrice = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $maxProductPrice = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $excludeAllProducts = false;

    // --- idealo-Einstellungen ---
    /** Lieferzeit-Text, z.B. "Lieferung in 1-3 Werktagen" (Pflichtfeld bei idealo) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idealoDelivery = null;

    /** Versanddienstleister für die Spalte deliveryCosts_<carrier> */
    #[ORM\Column(length: 30, options: ['default' => 'dhl'])]
    private string $idealoCarrier = 'dhl';

    /** Zahlungsarten mit Zuschlag, z.B. {"paypal": 0, "invoice": 0} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $idealoPaymentCosts = null;

    #[ORM\Column(nullable: true)]
    private ?int $idealoProcessingDays = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $idealoDeliveryComment = null;

    /** Nur lieferbare Artikel ("in stock") an idealo übergeben */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $idealoOnlyInStock = true;

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getFeedUrl(): ?string { return $this->feedUrl; }
    public function setFeedUrl(string $feedUrl): self { $this->feedUrl = $feedUrl; return $this; }

    public function getMinProductPrice(): ?float
    {
        return $this->minProductPrice !== null ? (float)$this->minProductPrice : null;
    }

    public function setMinProductPrice(?float $minProductPrice): self
    {
        $this->minProductPrice = $minProductPrice !== null ? (string)$minProductPrice : null;
        return $this;
    }

    public function getMaxProductPrice(): ?float
    {
        return $this->maxProductPrice !== null ? (float)$this->maxProductPrice : null;
    }

    public function setMaxProductPrice(?float $maxProductPrice): self
    {
        $this->maxProductPrice = $maxProductPrice !== null ? (string)$maxProductPrice : null;
        return $this;
    }

    // Der Getter (Symfony sucht bei Booleans bevorzugt nach "is...")
    public function isExcludeAllProducts(): bool
    {
        return $this->excludeAllProducts;
    }

// Alternativer Getter (falls Symfony explizit "get..." verlangt)
    public function getExcludeAllProducts(): bool
    {
        return $this->excludeAllProducts;
    }

// Der Setter
    public function setExcludeAllProducts(bool $excludeAllProducts): self
    {
        $this->excludeAllProducts = $excludeAllProducts;
        return $this;
    }

    public function getIdealoDelivery(): ?string { return $this->idealoDelivery; }
    public function setIdealoDelivery(?string $v): self { $this->idealoDelivery = $v !== null && trim($v) !== '' ? trim($v) : null; return $this; }

    public function getIdealoCarrier(): string { return $this->idealoCarrier; }
    public function setIdealoCarrier(?string $v): self { $this->idealoCarrier = $v ?: 'dhl'; return $this; }

    /** @return array<string, float> */
    public function getIdealoPaymentCosts(): array { return $this->idealoPaymentCosts ?? []; }
    public function setIdealoPaymentCosts(?array $v): self { $this->idealoPaymentCosts = $v ?: null; return $this; }

    public function getIdealoProcessingDays(): ?int { return $this->idealoProcessingDays; }
    public function setIdealoProcessingDays(?int $v): self { $this->idealoProcessingDays = $v; return $this; }

    public function getIdealoDeliveryComment(): ?string { return $this->idealoDeliveryComment; }
    public function setIdealoDeliveryComment(?string $v): self { $this->idealoDeliveryComment = $v !== null && trim($v) !== '' ? trim($v) : null; return $this; }

    public function isIdealoOnlyInStock(): bool { return $this->idealoOnlyInStock; }
    public function getIdealoOnlyInStock(): bool { return $this->idealoOnlyInStock; }
    public function setIdealoOnlyInStock(bool $v): self { $this->idealoOnlyInStock = $v; return $this; }
}
