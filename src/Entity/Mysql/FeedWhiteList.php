<?php

namespace App\Entity\Mysql;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'feed_white_list')]
class FeedWhiteList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $sku = null;

    #[ORM\ManyToOne(targetEntity: FeedConfig::class)]
    #[ORM\JoinColumn(name: 'feed_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: false)]
    private ?FeedConfig $feed = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;
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
}
