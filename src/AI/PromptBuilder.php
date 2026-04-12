<?php

namespace App\AI;

use App\Entity\ChatMessage;
use App\Entity\Project;

final class PromptBuilder
{
    public function __construct(private readonly ResponseSchema $responseSchema)
    {
    }

    /**
     * @param list<ChatMessage> $conversationHistory
     */
    public function build(Project $project, string $userMessage, array $conversationHistory = []): PromptRequest
    {
        return new PromptRequest(
            $project->getProvider(),
            $project->getModel(),
            $this->buildSystemPrompt($project),
            trim($userMessage),
            $this->normalizeConversationHistory($conversationHistory),
            $this->buildProjectContext($project),
        );
    }

    private function buildSystemPrompt(Project $project): string
    {
        $slides = $project->getSlides();
        $slideTitles = array_map(
            static fn (array $slide): string => (string) ($slide['title'] ?? $slide['id'] ?? 'Slide'),
            array_slice($slides, 0, 6),
        );

        $lines = [
            'You are Harmony, an AI copilot for presentation projects.',
            'Reply in concise French unless the user explicitly asks for another language.',
            'Stay grounded in the current project context and propose practical slide-oriented help.',
            'When changing the deck, return structured JSON actions that Harmony can validate and apply automatically.',
            'Project title: '.$project->getTitle(),
            'Project status: '.$project->getStatus(),
            'Configured provider: '.$project->getProvider(),
            'Configured model: '.$project->getModel(),
            'Slides count: '.$project->getSlidesCount(),
        ];

        if ($slideTitles !== []) {
            $lines[] = 'Current slide titles: '.implode(' | ', $slideTitles);
        }

        $metadata = $project->getMetadata();
        if ($metadata !== []) {
            $lines[] = 'Project metadata: '.json_encode($metadata, JSON_THROW_ON_ERROR);
        }

        $lines[] = $this->responseSchema->promptInstructions();

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectContext(Project $project): array
    {
        return [
            'title' => $project->getTitle(),
            'status' => $project->getStatus(),
            'provider' => $project->getProvider(),
            'model' => $project->getModel(),
            'slidesCount' => $project->getSlidesCount(),
            'slides' => array_slice($project->getSlides(), 0, 6),
            'theme' => $project->getThemeConfig(),
            'metadata' => $project->getMetadata(),
        ];
    }

    /**
     * @param list<ChatMessage> $conversationHistory
     *
     * @return list<array{role: string, content: string}>
     */
    private function normalizeConversationHistory(array $conversationHistory): array
    {
        $normalized = [];

        foreach ($conversationHistory as $message) {
            $normalized[] = [
                'role' => $message->isAssistant() ? 'assistant' : 'user',
                'content' => $message->getContent(),
            ];
        }

        return $normalized;
    }
}
