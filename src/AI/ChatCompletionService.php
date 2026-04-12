<?php

namespace App\AI;

use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;

final class ChatCompletionService
{
    /**
     * @param list<ChatMessage> $conversationHistory
     */
    public function __construct(
        private readonly ChatEngine $chatEngine,
    ) {
    }

    /**
     * @param list<ChatMessage> $conversationHistory
     */
    public function generateAssistantReply(Project $project, User $user, string $userMessage, array $conversationHistory = []): ChatGenerationResult
    {
        return $this->chatEngine->generateAssistantReply($project, $user, $userMessage, $conversationHistory);
    }
}
