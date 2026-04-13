<?php

namespace App\Chat;

use App\AI\ChatGenerationResult;
use App\Entity\ChatMessage;

/**
 * Value object returned by ChatGenerationOrchestrator after a successful generation.
 */
final class ChatGenerationOrchestratorResult
{
    public function __construct(
        private readonly ChatGenerationResult $generationResult,
        private readonly ChatMessage $assistantMessage,
    ) {
    }

    public function generationResult(): ChatGenerationResult
    {
        return $this->generationResult;
    }

    public function assistantMessage(): ChatMessage
    {
        return $this->assistantMessage;
    }
}
