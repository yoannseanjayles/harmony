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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
