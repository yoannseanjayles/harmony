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

    /** Number of slides in the project at the time of generation. */
    #[ORM\Column(options: ['default' => 0])]
    private int $slideCount = 0;

    /** Wall-clock duration in milliseconds from request start to completed AI response. */
    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /** Number of chat turns (user messages) sent in this session, including the current one. */
    #[ORM\Column(options: ['default' => 1])]
    private int $iterationCount = 1;

    /** Number of JSON-validation errors / provider retries that occurred during generation. */
    #[ORM\Column(options: ['default' => 0])]
    private int $errorCount = 0;

    /**
     * Number of AI-generated slides that were kept without any subsequent manual edit.
     * Initialised to slideCount at generation time; decremented when a slide is manually edited.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $acceptedSlideCount = 0;

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

    public function getSlideCount(): int
    {
        return $this->slideCount;
    }

    public function setSlideCount(int $slideCount): self
    {
        $this->slideCount = max(0, $slideCount);

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs !== null ? max(0, $durationMs) : null;

        return $this;
    }

    public function getIterationCount(): int
    {
        return $this->iterationCount;
    }

    public function setIterationCount(int $iterationCount): self
    {
        $this->iterationCount = max(1, $iterationCount);

        return $this;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function setErrorCount(int $errorCount): self
    {
        $this->errorCount = max(0, $errorCount);

        return $this;
    }

    public function getAcceptedSlideCount(): int
    {
        return $this->acceptedSlideCount;
    }

    public function setAcceptedSlideCount(int $acceptedSlideCount): self
    {
        $this->acceptedSlideCount = max(0, $acceptedSlideCount);

        return $this;
    }

    /**
     * Returns the acceptance rate as a float between 0.0 and 1.0.
     * Returns null when no slides were generated.
     */
    public function getAcceptanceRate(): ?float
    {
        if ($this->slideCount === 0) {
            return null;
        }

        return min(1.0, $this->acceptedSlideCount / $this->slideCount);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
