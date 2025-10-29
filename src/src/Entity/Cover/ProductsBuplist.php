<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsBuplistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsBuplistRepository::class)]
#[ORM\Table(name: 'BUPLIST')]
class ProductsBuplist
{
    #[ORM\Id]
    #[ORM\Column(name: "LIST_NR")]
    private ?int $id = null;

    #[ORM\Column(name: "L_AUSG_NR", length: 255)]
    private ?string $l_ausg_nr = null;

    #[ORM\Column(name: "PREISKAT", length: 255)]
    private ?string $preiskat = null;

    #[ORM\Column(name: "L_AUFL_NR", length: 255)]
    private ?string $l_aufl_nr = null;

    #[ORM\ManyToOne(targetEntity: Products::class)]
    #[ORM\JoinColumn(name: "L_AUSG_NR", referencedColumnName: "L_AUSG_NR", nullable: false)]
    private ?Products $product = null;

    /**
     * @var Collection<int, ProductsBumPreis>
     */
    #[ORM\OneToMany(targetEntity: ProductsBumPreis::class, mappedBy: 'buplist')]
    private Collection $productsBumPreis;

    #[ORM\Column(name: "GUELTIG_AB", type: 'string', length: 10, nullable: false)]
    private ?string $gueltig_ab = null;

    public function __construct()
    {
        $this->productsBumPreis = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLAusgNr(): ?string
    {
        return $this->l_ausg_nr;
    }

    public function setLAusgNr(string $l_ausg_nr): static
    {
        $this->l_ausg_nr = $l_ausg_nr;

        return $this;
    }

    public function getPreiskat(): ?string
    {
        return $this->preiskat;
    }

    public function setPreiskat(string $preiskat): static
    {
        $this->preiskat = $preiskat;

        return $this;
    }

    public function getLAuflNr(): ?string
    {
        return $this->l_aufl_nr;
    }

    public function setLAuflNr(string $l_aufl_nr): static
    {
        $this->l_aufl_nr = $l_aufl_nr;

        return $this;
    }

    public function getProduct(): ?Products
    {
        return $this->product;
    }

    public function setProduct(?Products $product): static
    {
        $this->product = $product;

        return $this;
    }

    /**
     * @return Collection<int, ProductsBumPreis>
     */
    public function getProductsBumPreis(): Collection
    {
        return $this->productsBumPreis;
    }

    public function addProductsBumPrei(ProductsBumPreis $productsBumPrei): static
    {
        if (!$this->productsBumPreis->contains($productsBumPrei)) {
            $this->productsBumPreis->add($productsBumPrei);
            $productsBumPrei->setBuplist($this);
        }

        return $this;
    }

    public function removeProductsBumPrei(ProductsBumPreis $productsBumPrei): static
    {
        if ($this->productsBumPreis->removeElement($productsBumPrei)) {
            // set the owning side to null (unless already changed)
            if ($productsBumPrei->getBuplist() === $this) {
                $productsBumPrei->setBuplist(null);
            }
        }

        return $this;
    }

    public function getGueltigAb(): ?\DateTime
    {
        if (!$this->gueltig_ab) return null;
        return \DateTime::createFromFormat('d.m.y', $this->gueltig_ab) ?: null;
    }

    public function setGueltigAb(\DateTimeInterface $gueltig_ab): static
    {

        $this->gueltig_ab = $gueltig_ab ? \DateTimeImmutable::createFromInterface($gueltig_ab) : null;
        return $this;
    }
}
