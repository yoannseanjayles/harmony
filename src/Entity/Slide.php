<?php

namespace App\Entity;

use App\Repository\SlideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SlideRepository::class)]
class Slide
{
    public const TYPE_TITLE = 'title';
    public const TYPE_CONTENT = 'content';
    public const TYPE_CLOSING = 'closing';
    public const TYPE_SPLIT = 'split';
    public const TYPE_IMAGE = 'image';
    public const TYPE_QUOTE = 'quote';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_CONTENT;

    #[ORM\Column(type: Types::TEXT, options: ['default' => '{}'])]
    private string $contentJson = '{}';

    #[ORM\Column]
    private int $position = 1;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $renderHash = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $htmlCache = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContent(): array
    {
        try {
            $decoded = json_decode($this->contentJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $content
     */
    public function setContent(array $content): self
    {
        try {
            $this->contentJson = (string) json_encode($content, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->contentJson = '{}';
        }

        return $this;
    }

    public function getContentJson(): string
    {
        return $this->contentJson;
    }

    public function setContentJson(string $contentJson): self
    {
        $this->contentJson = $contentJson;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getRenderHash(): ?string
    {
        return $this->renderHash;
    }

    public function setRenderHash(?string $renderHash): self
    {
        $this->renderHash = $renderHash;

        return $this;
    }

    public function getHtmlCache(): ?string
    {
        return $this->htmlCache;
    }

    public function setHtmlCache(?string $htmlCache): self
    {
        $this->htmlCache = $htmlCache;

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return [
            self::TYPE_TITLE,
            self::TYPE_CONTENT,
            self::TYPE_CLOSING,
            self::TYPE_SPLIT,
            self::TYPE_IMAGE,
            self::TYPE_QUOTE,
        ];
    }
}
