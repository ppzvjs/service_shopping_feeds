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
}
