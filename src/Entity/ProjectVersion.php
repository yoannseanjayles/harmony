<?php

namespace App\Entity;

use App\Repository\ProjectVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectVersionRepository::class)]
#[ORM\Table(name: 'project_version')]
#[ORM\UniqueConstraint(name: 'uniq_project_version_number', columns: ['project_id', 'version_number'])]
class ProjectVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column]
    private int $versionNumber = 1;

    #[ORM\Column(type: Types::TEXT, options: ['default' => '{}'])]
    private string $snapshotJson = '{}';

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

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): self
    {
        $this->versionNumber = $versionNumber;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        try {
            $decoded = json_decode($this->snapshotJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function setSnapshot(array $snapshot): self
    {
        try {
            $this->snapshotJson = (string) json_encode($snapshot, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->snapshotJson = '{}';
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
