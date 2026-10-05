<?php

namespace App\Entity\Settings;

use App\Repository\Settings\CurrencyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CurrencyRepository::class)]
#[ORM\Table(name: 'currency')]
class Currency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'short_name', length: 10)]
    #[Assert\NotBlank(message: 'Short name is required.')]
    #[Assert\Length(max: 10)]
    private string $shortName = '';

    /**
     * The only thing that ever said an FX rate is a number was
     * type="number" in the shared Settings form, so a posted "abc" reached
     * the decimal column: a 500 under strict SQL mode, and a silently
     * stored 0.00 without it -- a rate every converted amount is
     * multiplied by.
     */
    #[ORM\Column(name: 'fx_rate', type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'FX rate is required.')]
    #[Assert\Type(type: 'numeric', message: 'FX rate must be a number.')]
    private string $fxRate = '0.00';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

        return $this;
    }

    public function getFxRate(): string
    {
        return $this->fxRate;
    }

    public function setFxRate(string $fxRate): static
    {
        $this->fxRate = $fxRate;

        return $this;
    }
}
