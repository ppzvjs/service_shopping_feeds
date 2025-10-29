<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsBuAufTxtRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsBuAufTxtRepository::class)]
#[ORM\Table(name: 'BUAUFTXT')]
class ProductsBuAufTxt
{

    #[ORM\Id]
    #[ORM\Column(name: "L_AUSG_NR")]
    private ?int $l_ausg_nr = null;

    #[ORM\Id]
    #[ORM\Column(name: "L_AUFL_NR")]
    private ?int $l_aufl_nr = null;

    #[ORM\Id]
    #[ORM\Column(name: "AUFL_TEXT", length: 255)]
    private ?string $aufl_text = null;

    #[ORM\ManyToOne(targetEntity: Products::class)]
    #[ORM\JoinColumn(name: "L_AUSG_NR", referencedColumnName: "L_AUSG_NR", nullable: false)]
    private ?Products $product = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLAusgNr(): ?int
    {
        return $this->l_ausg_nr;
    }

    public function setLAusgNr(int $l_ausg_nr): static
    {
        $this->l_ausg_nr = $l_ausg_nr;

        return $this;
    }

    public function getLAuflNr(): ?int
    {
        return $this->l_aufl_nr;
    }

    public function setLAuflNr(int $l_aufl_nr): static
    {
        $this->l_aufl_nr = $l_aufl_nr;

        return $this;
    }

    public function getAuflText(): ?string
    {
        return $this->aufl_text;
    }

    public function setAuflText(string $aufl_text): static
    {
        $this->aufl_text = $aufl_text;

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
