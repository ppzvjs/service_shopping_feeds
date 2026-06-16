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

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getFeedUrl(): ?string { return $this->feedUrl; }
    public function setFeedUrl(string $feedUrl): self { $this->feedUrl = $feedUrl; return $this; }
}
