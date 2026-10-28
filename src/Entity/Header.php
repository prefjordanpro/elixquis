<?php

namespace App\Entity;

use App\Repository\HeaderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: HeaderRepository::class)]
class Header
{
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;
    #[ORM\Column(length: 255, options: ['default' => ''])]
    private string $alt = '';
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $value): static { $this->active = $value; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $value): static { $this->position = $value; return $this; }
    public function getAlt(): string { return $this->alt; }
    public function setAlt(string $value): static { $this->alt = $value; return $this; }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(length: 255)]
    private ?string $buttonTitle = null;

    #[ORM\Column(length: 255)]
    #[\App\Validator\PublicContentUrl]
    private ?string $buttonLink = null;

    #[ORM\Column(length: 255)]
    private ?string $illustration = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getButtonTitle(): ?string
    {
        return $this->buttonTitle;
    }

    public function setButtonTitle(string $buttonTitle): static
    {
        $this->buttonTitle = $buttonTitle;

        return $this;
    }

    public function getButtonLink(): ?string
    {
        return $this->buttonLink;
    }

    public function setButtonLink(string $buttonLink): static
    {
        $this->buttonLink = $buttonLink;

        return $this;
    }

    public function getIllustration(): ?string
    {
        return $this->illustration;
    }

    public function setIllustration(?string $illustration): static
    {
        $this->illustration = $illustration ?? $this->illustration;

        return $this;
    }
}
