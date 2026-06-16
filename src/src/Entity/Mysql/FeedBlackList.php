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

    public function getId(): ?int { return $this->id; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(string $sku): self { $this->sku = $sku; return $this; }
}
