<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stores the state and SSE events for an in-progress chat stream session.
 *
 * Replaces the previous filesystem-based ChatStreamSessionStore to support
 * cross-process communication between the Messenger worker and the SSE endpoint.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class ChatStreamSession
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $streamId;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $userMessageId;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(nullable: true)]
    private ?int $assistantMessageId = null;

    /** JSON-encoded list of SSE events. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => '[]'])]
    private string $eventsJson = '[]';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        // Ensure timestamps are set when persisting via Doctrine without constructor.
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStreamId(): string
    {
        return $this->streamId;
    }

    public function setStreamId(string $streamId): self
    {
        $this->streamId = $streamId;

        return $this;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUserMessageId(): int
    {
        return $this->userMessageId;
    }

    public function setUserMessageId(int $userMessageId): self
    {
        $this->userMessageId = $userMessageId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAssistantMessageId(): ?int
    {
        return $this->assistantMessageId;
    }

    public function setAssistantMessageId(?int $assistantMessageId): self
    {
        $this->assistantMessageId = $assistantMessageId;

        return $this;
    }

    /**
     * @return list<array{id: int, type: string, payload: array<string, mixed>}>
     */
    public function getEvents(): array
    {
        try {
            $decoded = json_decode($this->eventsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array{id: int, type: string, payload: array<string, mixed>}> $events
     */
    public function setEvents(array $events): self
    {
        $this->eventsJson = json_encode($events, JSON_THROW_ON_ERROR);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: int, type: string, payload: array<string, mixed>}
     */
    public function appendEvent(string $type, array $payload): array
    {
        $events = $this->getEvents();
        $lastEvent = $events === [] ? null : $events[array_key_last($events)];
        $nextId = is_array($lastEvent) ? ((int) ($lastEvent['id'] ?? 0)) + 1 : 1;

        $event = [
            'id' => $nextId,
            'type' => $type,
            'payload' => $payload,
        ];

        $events[] = $event;
        $this->setEvents($events);

        return $event;
    }

    /**
     * @return list<array{id: int, type: string, payload: array<string, mixed>}>
     */
    public function eventsAfter(int $lastEventId): array
    {
        return array_values(array_filter(
            $this->getEvents(),
            static fn (array $event): bool => (int) ($event['id'] ?? 0) > $lastEventId,
        ));
    }

    public function isOwnedBy(Project $project, User $user): bool
    {
        return $this->project->getId() === $project->getId()
            && $this->user->getId() === $user->getId();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
