<?php

namespace App\Entity;

use App\Repository\MediaAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * T209 — Represents an uploaded media asset associated with a project.
 *
 * - filename:   original client-provided filename (for display only)
 * - mimeType:   validated MIME type from the whitelist
 * - size:       file size in bytes
 * - storageKey: UUID-based filename used on disk (T214 — avoids collisions and path traversal)
 * - project:    owning project (required)
 * - slideRefs:  JSON array of slide IDs that reference this asset
 * - createdAt:  upload timestamp
 */
#[ORM\Entity(repositoryClass: MediaAssetRepository::class)]
class MediaAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $filename = '';

    #[ORM\Column(length: 100)]
    private string $mimeType = '';

    #[ORM\Column]
    private int $size = 0;

    /** UUID-based storage key (e.g. "550e8400-e29b-41d4-a716-446655440000.jpg") */
    #[ORM\Column(length: 255)]
    private string $storageKey = '';

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /**
     * JSON-encoded list of slide IDs that reference this asset.
     */
    #[ORM\Column(type: Types::TEXT, options: ['default' => '[]'])]
    private string $slideRefsJson = '[]';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function setStorageKey(string $storageKey): self
    {
        $this->storageKey = $storageKey;

        return $this;
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

    /**
     * @return list<string>
     */
    public function getSlideRefs(): array
    {
        try {
            $decoded = json_decode($this->slideRefsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param list<string> $slideRefs
     */
    public function setSlideRefs(array $slideRefs): self
    {
        try {
            $this->slideRefsJson = (string) json_encode($slideRefs, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->slideRefsJson = '[]';
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
