<?php

namespace App\Entity;

use App\Repository\ProjectExportMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectExportMetricRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProjectExportMetric
{
    public const FORMAT_HTML = 'html';
    public const FORMAT_PDF = 'pdf';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';

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

    #[Assert\Choice(callback: [self::class, 'allowedFormats'])]
    #[ORM\Column(length: 20)]
    private string $format = self::FORMAT_HTML;

    #[ORM\Column(options: ['default' => true])]
    private bool $wasSuccessful = true;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

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

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function wasSuccessful(): bool
    {
        return $this->wasSuccessful;
    }

    public function setWasSuccessful(bool $wasSuccessful): self
    {
        $this->wasSuccessful = $wasSuccessful;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): self
    {
        $this->failureReason = $failureReason;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->wasSuccessful ? self::STATUS_SUCCESS : self::STATUS_FAILURE;
    }

    /**
     * @return list<string>
     */
    public static function allowedFormats(): array
    {
        return [
            self::FORMAT_HTML,
            self::FORMAT_PDF,
        ];
    }
}
