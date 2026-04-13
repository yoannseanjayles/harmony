<?php

namespace App\Chat;

use App\AI\AICostCalculator;
use App\AI\ChatEngine;
use App\AI\ChatGenerationResult;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use App\Project\ProjectMetricsRecorder;
use App\Project\ProjectVersioning;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrates AI chat generation: calls ChatEngine, persists results,
 * records metrics, and publishes SSE events to the stream store.
 *
 * Extracted from ChatController to allow reuse from both the HTTP path
 * and the async Messenger handler.
 */
final class ChatGenerationOrchestrator
{
    public function __construct(
        private readonly ChatEngine $chatEngine,
        private readonly EntityManagerInterface $entityManager,
        private readonly ChatMessageRepository $chatMessageRepository,
        private readonly ProjectVersioning $projectVersioning,
        private readonly AICostCalculator $aiCostCalculator,
        private readonly ProjectMetricsRecorder $projectMetricsRecorder,
    ) {
    }

    /**
     * Runs the synchronous (non-streaming) generation path.
     *
     * Persists the assistant message, updates slides/pending confirmation,
     * records metrics, and returns the result.
     *
     * @param list<ChatMessage> $priorConversation
     */
    public function generateSync(
        Project $project,
        User $user,
        ChatMessage $userMessage,
        array $priorConversation,
    ): ChatGenerationOrchestratorResult {
        $generationStartedAt = microtime(true);

        $generationResult = $this->chatEngine->generateAssistantReply(
            $project,
            $user,
            $userMessage->getContent(),
            $priorConversation,
        );

        $assistantMessage = $this->persistGenerationResult($project, $generationResult);

        $this->recordMetrics(
            $project,
            $generationResult,
            $generationStartedAt,
            count($priorConversation),
        );

        return new ChatGenerationOrchestratorResult(
            $generationResult,
            $assistantMessage,
        );
    }

    /**
     * Runs the streaming generation path.
     *
     * @param list<ChatMessage> $priorConversation
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    public function generateStream(
        Project $project,
        User $user,
        ChatMessage $userMessage,
        array $priorConversation,
        callable $onEvent,
    ): ChatGenerationOrchestratorResult {
        $generationStartedAt = microtime(true);

        $generationResult = $this->chatEngine->streamAssistantReply(
            $project,
            $user,
            $userMessage,
            $priorConversation,
            $onEvent,
        );

        $assistantMessage = $this->persistGenerationResultInTransaction($project, $generationResult);

        $this->recordMetrics(
            $project,
            $generationResult,
            $generationStartedAt,
            count($priorConversation),
        );

        return new ChatGenerationOrchestratorResult(
            $generationResult,
            $assistantMessage,
        );
    }

    /**
     * Returns the estimated cost in USD for the generation.
     */
    public function calculateCostUsd(ChatGenerationResult $generationResult): float
    {
        $pr = $generationResult->providerResponse();

        return $this->aiCostCalculator->calculateUsd(
            $pr->model(),
            $pr->inputTokens() ?? 0,
            $pr->outputTokens() ?? 0,
        );
    }

    private function persistGenerationResult(Project $project, ChatGenerationResult $generationResult): ChatMessage
    {
        $assistantMessage = (new ChatMessage())
            ->setProject($project)
            ->setRole(ChatMessage::ROLE_ASSISTANT)
            ->setContent($generationResult->assistantContent());

        $this->entityManager->persist($assistantMessage);

        if ($generationResult->requiresConfirmation()) {
            $project->storePendingConfirmation($generationResult->pendingConfirmation() ?? []);
        } elseif ($generationResult->slidesChanged()) {
            $project->setSlides($generationResult->slides());
        }

        $this->entityManager->flush();

        if ($generationResult->slidesChanged()) {
            $this->projectVersioning->captureSnapshot($project);
        }

        return $assistantMessage;
    }

    private function persistGenerationResultInTransaction(Project $project, ChatGenerationResult $generationResult): ChatMessage
    {
        $assistantMessage = (new ChatMessage())
            ->setProject($project)
            ->setRole(ChatMessage::ROLE_ASSISTANT)
            ->setContent($generationResult->assistantContent());

        $this->entityManager->wrapInTransaction(function () use (
            $project,
            $generationResult,
            $assistantMessage,
        ): void {
            $this->entityManager->persist($assistantMessage);

            if ($generationResult->requiresConfirmation()) {
                $project->storePendingConfirmation($generationResult->pendingConfirmation() ?? []);
            } elseif ($generationResult->slidesChanged()) {
                $project->setSlides($generationResult->slides());
            }

            $this->entityManager->flush();

            if ($generationResult->slidesChanged()) {
                $this->projectVersioning->captureSnapshot($project);
            }
        });

        return $assistantMessage;
    }

    private function recordMetrics(
        Project $project,
        ChatGenerationResult $generationResult,
        float $generationStartedAt,
        int $priorConversationCount,
    ): void {
        try {
            $estimatedCostUsd = $this->calculateCostUsd($generationResult);
            $pr = $generationResult->providerResponse();

            $this->projectMetricsRecorder->recordGeneration(
                project: $project,
                provider: $pr->provider(),
                model: $pr->model(),
                estimatedCostUsd: $estimatedCostUsd,
                slideCount: $project->getSlidesCount(),
                durationMs: (int) round((microtime(true) - $generationStartedAt) * 1000),
                iterationCount: $priorConversationCount + 1,
                errorCount: $generationResult->errorCount(),
            );
        } catch (\Throwable) {
            // Metric recording must never block generation
        }
    }
}
