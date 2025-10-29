<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsBuplistRepository;
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
}
