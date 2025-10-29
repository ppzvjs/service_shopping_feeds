<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsRepository::class)]
#[ORM\Table(name: 'BUAUSGAB')]
class Products implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(name: "L_AUSG_NR", length: 10)]
    private ?int $l_ausg_nr = null;

    #[ORM\Column(name: "AUSG_TEXT", length: 255)]
    private ?string $title = null;

    /**
     * @var Collection<int, ProductsMeta>
     */
    #[ORM\OneToMany(targetEntity: ProductsMeta::class, mappedBy: 'products')]
    private Collection $productsMetas;

    public function __construct()
    {
        $this->productsMetas = new ArrayCollection();
    }


    public function getLAusgNr(): ?int
    {
        return $this->l_ausg_nr;
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

    /**
     * @return Collection<int, ProductsMeta>
     */
    public function getProductsMetas(): Collection
    {
        return $this->productsMetas;
    }

    public function addProductsMeta(ProductsMeta $productsMeta): static
    {
        if (!$this->productsMetas->contains($productsMeta)) {
            $this->productsMetas->add($productsMeta);
            $productsMeta->setProducts($this);
        }

        return $this;
    }

    public function removeProductsMeta(ProductsMeta $productsMeta): static
    {
        if ($this->productsMetas->removeElement($productsMeta)) {
            // set the owning side to null (unless already changed)
            if ($productsMeta->getProducts() === $this) {
                $productsMeta->setProducts(null);
            }
        }

        return $this;
    }

    public function jsonSerialize(): mixed
    {
        $artnr = null;
        foreach($this->getProductsMetas() as $meta){;
            $artnr = $meta->getArtikelNr();
        }
        return [
            'l_ausg_nr' => $this->getLAusgNr(),
            'title' => $this->getTitle(),
            'artikel_nr' => $artnr,
        ];
    }
}
