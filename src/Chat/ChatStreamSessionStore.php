<?php

namespace App\Chat;

use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChatStreamSessionStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/chat_streams')]
        private readonly string $streamDirectory,
    ) {
    }

    public function create(Project $project, User $user, ChatMessage $userMessage): string
    {
        $this->ensureDirectoryExists();

        $streamId = bin2hex(random_bytes(18));
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);

        $this->writeState($streamId, [
            'streamId' => $streamId,
            'projectId' => $project->getId(),
            'userId' => $user->getId(),
            'userMessageId' => $userMessage->getId(),
            'status' => 'pending',
            'assistantMessageId' => null,
            'events' => [],
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        return $streamId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $streamId): ?array
    {
        $path = $this->statePath($streamId);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return resource
     */
    public function acquireLock(string $streamId)
    {
        $this->ensureDirectoryExists();

        $handle = fopen($this->lockPath($streamId), 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create chat stream lock file.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function markStatus(string $streamId, string $status, ?int $assistantMessageId = null): void
    {
        $state = $this->load($streamId);
        if (!is_array($state)) {
            return;
        }

        $state['status'] = $status;
        $state['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        if ($assistantMessageId !== null) {
            $state['assistantMessageId'] = $assistantMessageId;
        }

        $this->writeState($streamId, $state);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: int, type: string, payload: array<string, mixed>}
     */
    public function appendEvent(string $streamId, string $type, array $payload): array
    {
        $state = $this->load($streamId);
        if (!is_array($state)) {
            throw new \RuntimeException('Chat stream session not found.');
        }

        $events = is_array($state['events'] ?? null) ? $state['events'] : [];
        $lastEvent = $events === [] ? null : $events[array_key_last($events)];
        $nextId = is_array($lastEvent) ? ((int) ($lastEvent['id'] ?? 0)) + 1 : 1;

        $event = [
            'id' => $nextId,
            'type' => $type,
            'payload' => $payload,
        ];

        $events[] = $event;
        $state['events'] = $events;
        $state['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeState($streamId, $state);

        return $event;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return list<array{id: int, type: string, payload: array<string, mixed>}>
     */
    public function eventsAfter(array $state, int $lastEventId): array
    {
        $events = is_array($state['events'] ?? null) ? $state['events'] : [];

        return array_values(array_filter($events, static fn (mixed $event): bool => is_array($event) && (int) ($event['id'] ?? 0) > $lastEventId));
    }

    public function isOwnedBy(array $state, Project $project, User $user): bool
    {
        return (int) ($state['projectId'] ?? 0) === $project->getId()
            && (int) ($state['userId'] ?? 0) === $user->getId();
    }

    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->streamDirectory)) {
            return;
        }

        mkdir($this->streamDirectory, 0777, true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(string $streamId, array $state): void
    {
        file_put_contents(
            $this->statePath($streamId),
            json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX,
        );
    }

    private function statePath(string $streamId): string
    {
        return rtrim($this->streamDirectory, '\\/').DIRECTORY_SEPARATOR.$streamId.'.json';
    }

    private function lockPath(string $streamId): string
    {
        return rtrim($this->streamDirectory, '\\/').DIRECTORY_SEPARATOR.$streamId.'.lock';
    }
}
