<?php
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

    #[ORM\Column(type: 'text')]
    private ?string $feedUrl = null;

    public function getId(): ?int { return $this->id; }
    public function getFeedUrl(): ?string { return $this->feedUrl; }
    public function setFeedUrl(string $feedUrl): self { $this->feedUrl = $feedUrl; return $this; }
}
