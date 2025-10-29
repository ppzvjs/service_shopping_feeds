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

    /**
     * @var Collection<int, ProductsBuplist>
     */
    #[ORM\OneToMany(targetEntity: ProductsBuplist::class, mappedBy: 'product')]
    private Collection $productsBuplists;

    /**
     * @var Collection<int, ProductsC2Wart>
     */
    #[ORM\OneToMany(targetEntity: ProductsC2Wart::class, mappedBy: 'product')]
    private Collection $productsC2Warts;

    public function __construct()
    {
        $this->productsMetas = new ArrayCollection();
        $this->productsBuplists = new ArrayCollection();
        $this->productsC2Warts = new ArrayCollection();
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
        foreach($this->getProductsMetas() as $meta){;
            $artnr[] = $meta->getArtikelNr();
        }
        foreach($this->getProductsBuplists() as $buplist){;
            $preiskat[] = $buplist->getPreiskat();
        }
        foreach($this->getProductsC2Warts() as $c2wart){;
            $ausgnr[] = $c2wart->getSku() . ",";
        }
        return [
            'l_ausg_nr' => $this->getLAusgNr(),
            'title' => $this->getTitle(),
            'artikel_nr' => implode(",", $artnr),
            'preiskat' => implode(",", $preiskat),
            'l_ausg_nrs_c2wart' => implode("", $ausgnr),
        ];
    }

    /**
     * @return Collection<int, ProductsBuplist>
     */
    public function getProductsBuplists(): Collection
    {
        return $this->productsBuplists;
    }

    public function addProductsBuplist(ProductsBuplist $productsBuplist): static
    {
        if (!$this->productsBuplists->contains($productsBuplist)) {
            $this->productsBuplists->add($productsBuplist);
            $productsBuplist->setProduct($this);
        }

        return $this;
    }

    public function removeProductsBuplist(ProductsBuplist $productsBuplist): static
    {
        if ($this->productsBuplists->removeElement($productsBuplist)) {
            // set the owning side to null (unless already changed)
            if ($productsBuplist->getProduct() === $this) {
                $productsBuplist->setProduct(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProductsC2Wart>
     */
    public function getProductsC2Warts(): Collection
    {
        return $this->productsC2Warts;
    }

    public function addProductsC2Wart(ProductsC2Wart $productsC2Wart): static
    {
        if (!$this->productsC2Warts->contains($productsC2Wart)) {
            $this->productsC2Warts->add($productsC2Wart);
            $productsC2Wart->setProduct($this);
        }

        return $this;
    }

    public function removeProductsC2Wart(ProductsC2Wart $productsC2Wart): static
    {
        if ($this->productsC2Warts->removeElement($productsC2Wart)) {
            // set the owning side to null (unless already changed)
            if ($productsC2Wart->getProduct() === $this) {
                $productsC2Wart->setProduct(null);
            }
        }

        return $this;
    }
}
