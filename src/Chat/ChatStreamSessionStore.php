<?php

namespace App\Chat;

use App\Entity\ChatMessage;
use App\Entity\ChatStreamSession;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages chat stream sessions backed by Doctrine (DB table).
 *
 * This replaces the previous filesystem-based implementation to support
 * cross-process communication between Messenger workers and the SSE endpoint.
 *
 * The public API is preserved so that ChatController and
 * GenerateChatReplyHandler continue to work without changes.
 */
final class ChatStreamSessionStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function create(Project $project, User $user, ChatMessage $userMessage): string
    {
        $streamId = bin2hex(random_bytes(18));

        $session = new ChatStreamSession();
        $session->setStreamId($streamId);
        $session->setProject($project);
        $session->setUser($user);
        $session->setUserMessageId($userMessage->getId());

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $streamId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $streamId): ?array
    {
        $session = $this->findSession($streamId);
        if ($session === null) {
            return null;
        }

        return $this->toStateArray($session);
    }

    /**
     * Acquires a logical lock on a stream session.
     *
     * Uses an atomic UPDATE with a WHERE condition to prevent two workers
     * from processing the same stream session concurrently.
     *
     * @return true|false  Returns true on success, false if already locked.
     */
    public function acquireLock(string $streamId): mixed
    {
        // Atomic conditional update: only mark as 'streaming' if currently 'pending'.
        $rowsAffected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE chat_stream_session SET status = :newStatus, updated_at = :now WHERE stream_id = :id AND status = :currentStatus',
            [
                'newStatus' => 'streaming',
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $streamId,
                'currentStatus' => 'pending',
            ],
        );

        if ($rowsAffected === 0) {
            return false;
        }

        // Refresh the entity manager's view of this entity.
        $session = $this->findSession($streamId);
        if ($session !== null) {
            $this->entityManager->refresh($session);
        }

        return true;
    }

    /**
     * @param mixed $handle  The lock handle returned by acquireLock (true or a file resource for backwards compat).
     */
    public function releaseLock(mixed $handle): void
    {
        // No-op for the DB-backed store — lock state is managed by markStatus.
    }

    public function markStatus(string $streamId, string $status, ?int $assistantMessageId = null): void
    {
        $session = $this->findSession($streamId);
        if ($session === null) {
            return;
        }

        $session->setStatus($status);
        if ($assistantMessageId !== null) {
            $session->setAssistantMessageId($assistantMessageId);
        }

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: int, type: string, payload: array<string, mixed>}
     */
    public function appendEvent(string $streamId, string $type, array $payload): array
    {
        $session = $this->findSession($streamId);
        if ($session === null) {
            throw new \RuntimeException('Chat stream session not found.');
        }

        $event = $session->appendEvent($type, $payload);
        $this->entityManager->flush();

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

        return array_values(array_filter(
            $events,
            static fn (mixed $event): bool => is_array($event) && (int) ($event['id'] ?? 0) > $lastEventId,
        ));
    }

    /**
     * @param array<string, mixed> $state
     */
    public function isOwnedBy(array $state, Project $project, User $user): bool
    {
        return (int) ($state['projectId'] ?? 0) === $project->getId()
            && (int) ($state['userId'] ?? 0) === $user->getId();
    }

    private function findSession(string $streamId): ?ChatStreamSession
    {
        return $this->entityManager->getRepository(ChatStreamSession::class)->find($streamId);
    }

    /**
     * @return array<string, mixed>
     */
    private function toStateArray(ChatStreamSession $session): array
    {
        return [
            'streamId' => $session->getStreamId(),
            'projectId' => $session->getProject()->getId(),
            'userId' => $session->getUser()->getId(),
            'userMessageId' => $session->getUserMessageId(),
            'status' => $session->getStatus(),
            'assistantMessageId' => $session->getAssistantMessageId(),
            'events' => $session->getEvents(),
            'createdAt' => $session->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $session->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
