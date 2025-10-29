<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsC2WartRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsC2WartRepository::class)]
#[ORM\Table(name: 'C2WART')]
class ProductsC2Wart
{
    #[ORM\Id]
    #[ORM\Column(name: "LFD_NR")]
    private ?int $lfd_nr = null;

    #[ORM\Column(name: "L_AUSG_NR", length: 255)]
    private ?int $l_ausg_nr = null;

    #[ORM\Column(name: "L_AUFL_NR", length: 255)]
    private ?string $l_aufl_nr = null;

    #[ORM\Column(name: "SHOP_STATUS", length: 255)]
    private ?string $shop_status = null;

    #[ORM\Column(name: "ARTIKEL_URL_WS", length: 255)]
    private ?string $artikel_url_ws = null;

    #[ORM\Column(name: "SEO_DESCRIPTION", length: 255)]
    private ?string $seo = null;

    #[ORM\Column(name: "SKU", length: 255)]
    private ?string $sku = null;

    #[ORM\Column(name: "VERSAND_KOST_GRP", length: 255)]
    private ?string $versand_kost_grp = null;

    #[ORM\ManyToOne(targetEntity: Products::class)]
    #[ORM\JoinColumn(name: "L_AUSG_NR", referencedColumnName: "L_AUSG_NR", nullable: false)]
    private ?Products $product = null;

    #[ORM\Column(name: "SICHTBARKEIT")]
    private ?int $sichtbarkeit = null;

    public function getLfdNr(): ?int
    {
        return $this->lfd_nr;
    }

    public function getLAusgNr(): ?int
    {
        return $this->l_ausg_nr;
    }

    public function setLAusgNr(string $l_ausg_nr): static
    {
        $this->l_ausg_nr = $l_ausg_nr;

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

    public function getShopStatus(): ?string
    {
        return $this->shop_status;
    }

    public function setShopStatus(string $shop_status): static
    {
        $this->shop_status = $shop_status;

        return $this;
    }

    public function getArtikelUrlWs(): ?string
    {
        return $this->artikel_url_ws;
    }

    public function setArtikelUrlWs(string $artikel_url_ws): static
    {
        $this->artikel_url_ws = $artikel_url_ws;

        return $this;
    }

    public function getSeo(): ?string
    {
        return $this->seo;
    }

    public function setSeo(string $seo): static
    {
        $this->seo = $seo;

        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;

        return $this;
    }

    public function getVersandKostGrp(): ?string
    {
        return $this->versand_kost_grp;
    }

    public function setVersandKostGrp(string $versand_kost_grp): static
    {
        $this->versand_kost_grp = $versand_kost_grp;

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

    public function getSichtbarkeit(): ?int
    {
        return $this->sichtbarkeit;
    }

    public function setSichtbarkeit(int $sichtbarkeit): static
    {
        $this->sichtbarkeit = $sichtbarkeit;

        return $this;
    }
}
