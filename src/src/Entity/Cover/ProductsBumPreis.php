<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsBumPreisRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsBumPreisRepository::class)]
#[ORM\Table(name: 'BUMPREIS')]
class ProductsBumPreis
{
    #[ORM\Id]
    #[ORM\Column(name: "LIST_NR")]
    private ?int $id = null;

    #[ORM\Column(name: "MENGE")]
    private ?int $menge = null;

    // store as DECIMAL(10,2) in DB, Doctrine will hydrate as string
    #[ORM\Column(name: "PREIS", type: 'decimal', precision: 10, scale: 2)]
    private ?string $preis = null;

    #[ORM\ManyToOne(targetEntity: ProductsBuplist::class)]
    #[ORM\JoinColumn(name: "LIST_NR", referencedColumnName: "LIST_NR", nullable: false)]
    private ?ProductsBuplist $buplist = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMenge(): ?int
    {
        return $this->menge;
    }

    public function setMenge(int $menge): static
    {
        $this->menge = $menge;
        return $this;
    }

    // ✅ Doctrine returns decimal as string, convert safely to float here
    public function getPreis(): ?float
    {
        return $this->preis !== null ? (float) $this->preis : null;
    }

    // ✅ Setter accepts float, but stores string (so Doctrine won't complain)
    public function setPreis(float $preis): static
    {
        $this->preis = number_format($preis, 2, '.', '');
        return $this;
    }

    public function getBuplist(): ?ProductsBuplist
    {
        return $this->buplist;
    }

    public function setBuplist(?ProductsBuplist $buplist): static
    {
        $this->buplist = $buplist;
        return $this;
    }
}
