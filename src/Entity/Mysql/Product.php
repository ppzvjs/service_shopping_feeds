<?php

namespace App\Entity\Mysql;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ORM\UniqueConstraint(name: 'unique_product_per_feed', columns: ['remote_id', 'feed_id'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'remote_id', length: 255)]
    private ?string $remoteId = null;

    #[ORM\ManyToOne(targetEntity: FeedConfig::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FeedConfig $feed = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private ?string $link = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $productType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $availability = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $imageLink = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $additionalImages = [];

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $salePrice = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $shippingCost = null;

    // --- GETTER & SETTER ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRemoteId(): ?string
    {
        return $this->remoteId;
    }

    public function setRemoteId(string $remoteId): self
    {
        $this->remoteId = $remoteId;
        return $this;
    }

    public function getFeed(): ?FeedConfig
    {
        return $this->feed;
    }

    public function setFeed(?FeedConfig $feed): self
    {
        $this->feed = $feed;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(string $link): self
    {
        $this->link = $link;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getManufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function setManufacturer(?string $manufacturer): self
    {
        $this->manufacturer = $manufacturer;
        return $this;
    }

    public function getProductType(): ?string
    {
        return $this->productType;
    }

    public function setProductType(?string $productType): self
    {
        $this->productType = $productType;
        return $this;
    }

    public function getAvailability(): ?string
    {
        return $this->availability;
    }

    public function setAvailability(?string $availability): self
    {
        $this->availability = $availability;
        return $this;
    }

    public function getImageLink(): ?string
    {
        return $this->imageLink;
    }

    public function setImageLink(?string $imageLink): self
    {
        $this->imageLink = $imageLink;
        return $this;
    }

    public function getAdditionalImages(): ?array
    {
        return $this->additionalImages;
    }

    public function setAdditionalImages(?array $additionalImages): self
    {
        $this->additionalImages = $additionalImages;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price !== null ? (float)$this->price : null;
    }

    public function setPrice(float $price): self
    {
        $this->price = (string)$price;
        return $this;
    }

    public function getSalePrice(): ?float
    {
        return $this->salePrice !== null ? (float)$this->salePrice : null;
    }

    public function setSalePrice(?float $salePrice): self
    {
        $this->salePrice = $salePrice !== null ? (string)$salePrice : null;
        return $this;
    }

    public function getShippingCost(): ?float
    {
        return $this->shippingCost !== null ? (float)$this->shippingCost : null;
    }

    public function setShippingCost(?float $shippingCost): self
    {
        $this->shippingCost = $shippingCost !== null ? (string)$shippingCost : null;
        return $this;
    }

    /**
     * Hilfsmethode, um den aktuell gültigen Preis zu ermitteln (Sonderpreis vor Normalpreis)
     */
    public function getActivePrice(): float
    {
        if ($this->getSalePrice() !== null && $this->getSalePrice() > 0) {
            return $this->getSalePrice();
        }
        return $this->getPrice() ?? 0.00;
    }
}
