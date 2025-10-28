<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsRepository::class)]
#[ORM\Table(name: 'BUAUSGAB')]
class Products
{
    #[ORM\Id]
    #[ORM\Column(name: "L_AUSG_NR", length: 10)]
    private ?int $id = null;

    #[ORM\Column(name: "AUSG_TEXT", length: 255)]
    private ?string $title = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }
}
