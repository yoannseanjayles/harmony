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
        private readonly ProviderFactory $providerFactory,
        private readonly PromptBuilder $promptBuilder,
    ) {
    }

    /**
     * @param list<ChatMessage> $conversationHistory
     */
    public function generateAssistantReply(Project $project, User $user, string $userMessage, array $conversationHistory = []): ProviderResponse
    {
        $provider = $this->providerFactory->createForProject($project, $user);
        $promptRequest = $this->promptBuilder->build($project, $userMessage, $conversationHistory);

        return $provider->sendPrompt($promptRequest);
    }
}
