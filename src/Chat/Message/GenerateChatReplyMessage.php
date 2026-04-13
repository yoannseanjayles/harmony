<?php

namespace App\Chat\Message;

/**
 * Messenger message dispatched when a user sends a chat message.
 *
 * The async handler will call the AI provider, persist the assistant
 * reply, and publish SSE events to the stream session store.
 */
final class GenerateChatReplyMessage
{
    public function __construct(
        private readonly int $projectId,
        private readonly int $userId,
        private readonly int $userMessageId,
        private readonly string $streamId,
    ) {
    }

    public function getProjectId(): int
    {
        return $this->projectId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUserMessageId(): int
    {
        return $this->userMessageId;
    }

    public function getStreamId(): string
    {
        return $this->streamId;
    }
}
