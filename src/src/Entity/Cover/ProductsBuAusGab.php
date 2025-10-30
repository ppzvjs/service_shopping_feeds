<?php

namespace App\Entity\Cover;

use App\Repository\Cover\ProductsBuAusGabRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsBuAusGabRepository::class)]
#[ORM\Table(name: 'BUAUSGAB')]
class ProductsBuAusGab
{
    #[ORM\Id]
    #[ORM\Column(name: "L_AUSG_NR")]
    private ?int $id = null;

    #[ORM\Column(name: "AUSG_TEXT", length: 255)]
    private ?string $ausg_text = null;

    #[ORM\Column(name: "WARN_ID", length: 255)]
    private ?string $warn_id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAusgText(): ?string
    {
        return $this->ausg_text;
    }

    public function setAusgText(string $ausg_text): static
    {
        $this->ausg_text = $ausg_text;

        return $this;
    }

    public function getWarnId(): ?string
    {
        return $this->warn_id;
    }

    public function setWarnId(string $warn_id): static
    {
        $this->warn_id = $warn_id;

        return $this;
    }
}
