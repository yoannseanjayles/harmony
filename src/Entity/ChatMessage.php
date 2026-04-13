<?php

namespace App\Entity;

use App\Repository\ChatMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ChatMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

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

    #[Assert\Choice(callback: [self::class, 'allowedRoles'], message: 'chat.role.invalid')]
    #[ORM\Column(length: 20)]
    private string $role = self::ROLE_USER;

    #[Assert\NotBlank(message: 'chat.content.required')]
    #[Assert\Length(max: 4000, maxMessage: 'chat.content.max_length')]
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    #[Assert\Choice(callback: [self::class, 'allowedStatuses'], message: 'chat.status.invalid')]
    #[ORM\Column(length: 20, options: ['default' => 'done'])]
    private string $status = self::STATUS_DONE;

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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = trim($content);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isGenerating(): bool
    {
        return $this->status === self::STATUS_GENERATING;
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * @return list<string>
     */
    public static function allowedRoles(): array
    {
        return [
            self::ROLE_USER,
            self::ROLE_ASSISTANT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_GENERATING,
            self::STATUS_DONE,
            self::STATUS_ERROR,
        ];
    }
}
