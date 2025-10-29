<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsMetaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsMetaRepository::class)]
#[ORM\Table(name: 'BUMANAUS')]
class ProductsMeta
{
    #[ORM\Id]
    #[ORM\Column(name: "L_AUSG_NR")]
    private ?int $id = null;

    #[ORM\Column(name: "ARTIKEL_NR", length: 255)]
    private ?string $artikel_nr = null;


    #[ORM\ManyToOne(targetEntity: Products::class)]
    #[ORM\JoinColumn(name: "L_AUSG_NR", referencedColumnName: "L_AUSG_NR", nullable: false)]
    private ?Products $products = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArtikelNr(): ?string
    {
        return $this->artikel_nr;
    }

    public function setArtikelNr(string $artikel_nr): static
    {
        $this->artikel_nr = $artikel_nr;

        return $this;
    }

    public function getProducts(): ?Products
    {
        return $this->products;
    }

    public function setProducts(?Products $products): static
    {
        $this->products = $products;

        return $this;
    }
}
