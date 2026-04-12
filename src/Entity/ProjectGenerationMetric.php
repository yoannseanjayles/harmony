<?php

namespace App\Entity;

use App\Repository\ProjectGenerationMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectGenerationMetricRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProjectGenerationMetric
{
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 40)]
    private string $provider = 'openai';

    #[ORM\Column(length: 80)]
    private string $model = 'gpt-4.1-mini';

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedCostCents = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getEstimatedCostCents(): int
    {
        return $this->estimatedCostCents;
    }

    public function setEstimatedCostUsd(string|float|int $estimatedCostUsd): self
    {
        $normalizedCost = max(0.0, round((float) $estimatedCostUsd, 2));
        $this->estimatedCostCents = (int) round($normalizedCost * 100);

        return $this;
    }

    public function getEstimatedCostUsd(): float
    {
        return round($this->estimatedCostCents / 100, 2);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
