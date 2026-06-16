<?php
namespace App\Entity\Mysql;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'feed_blacklist')]
class FeedBlackList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $sku = null;

    #[ORM\ManyToOne(targetEntity: FeedConfig::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FeedConfig $feed = null;

    public function getId(): ?int { return $this->id; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(string $sku): self { $this->sku = $sku; return $this; }

    public function getFeed(): ?FeedConfig { return $this->feed; }
    public function setFeed(?FeedConfig $feed): self { $this->feed = $feed; return $this; }
}
