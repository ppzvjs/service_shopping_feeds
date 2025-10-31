<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsC2WartmedRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsC2WartmedRepository::class)]
#[ORM\Table(name: 'C2WARTMED')]
class ProductsC2Wartmed
{
    #[ORM\Id]
    #[ORM\Column(name: "LFD_NR_C2WART")]
    private ?int $lfd_nr_c2wart = null;

    #[ORM\Id]
    #[ORM\Column(name: "LFD_NR")]
    private ?int $lfd_nr = null;

    #[ORM\Column(name: "VERWEND_TYP", length: 255)]
    private ?string $verwend_typ = null;

    #[ORM\Column(name: "POSITION")]
    private ?int $position = null;

    #[ORM\Column(name: "DATEINAME", length: 255)]
    private ?string $dateiname = null;

    public function getLfdNrC2Wart(): ?int
    {
        return $this->lfd_nr_c2wart;
    }

    public function getLfdNr(): ?int
    {
        return $this->lfd_nr;
    }

    public function setLfdNr(int $lfd_nr): static
    {
        $this->lfd_nr = $lfd_nr;

        return $this;
    }

    public function getVerwendTyp(): ?string
    {
        return $this->verwend_typ;
    }

    public function setVerwendTyp(string $verwend_typ): static
    {
        $this->verwend_typ = $verwend_typ;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getDateiname(): ?string
    {
        return $this->dateiname;
    }

    public function setDateiname(string $dateiname): static
    {
        $this->dateiname = $dateiname;

        return $this;
    }
}
