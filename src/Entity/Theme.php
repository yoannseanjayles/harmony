<?php

namespace App\Entity;

use App\Repository\ThemeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * T177 — Theme entity storing CSS design-token overrides for a project preset or user theme.
 *
 * - isPreset = true  → built-in preset (cinematic, corporate, épuré); user is null.
 * - isPreset = false → user-owned customisation; user is set.
 */
#[ORM\Entity(repositoryClass: ThemeRepository::class)]
class Theme
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $name = '';

    /**
     * JSON object whose keys are CSS custom-property names (--hm-*) and values are their
     * replacement values.  Used by ThemeEngine::toCssBlock() to generate the override block.
     */
    #[ORM\Column(type: Types::TEXT, options: ['default' => '{}'])]
    private string $tokensJson = '{}';

    /**
     * Incremented whenever the token values are updated so SlideRenderHashCalculator
     * can detect stale caches without re-hashing the full token payload.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPreset = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getTokensJson(): string
    {
        return $this->tokensJson;
    }

    public function setTokensJson(string $tokensJson): self
    {
        $this->tokensJson = $tokensJson;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getTokens(): array
    {
        try {
            $decoded = json_decode($this->tokensJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $tokens
     */
    public function setTokens(array $tokens): self
    {
        try {
            $this->tokensJson = (string) json_encode($tokens, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            $this->tokensJson = '{}';
        }

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function isPreset(): bool
    {
        return $this->isPreset;
    }

    public function setIsPreset(bool $isPreset): self
    {
        $this->isPreset = $isPreset;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }
}
