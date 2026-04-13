<?php

namespace App\AI;

final class PromptRequest
{
    /**
     * @param list<array{role: string, content: string}> $conversationHistory
     * @param array<string, mixed> $projectContext
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $systemPrompt,
        #[\SensitiveParameter]
        private readonly string $userMessage,
        private readonly array $conversationHistory = [],
        private readonly array $projectContext = [],
        private readonly int $maxTokens = 16000,
        private readonly float $temperature = 0.8,
    ) {
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function systemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function conversationHistory(): array
    {
        return $this->conversationHistory;
    }

    /**
     * @return array<string, mixed>
     */
    public function projectContext(): array
    {
        return $this->projectContext;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    public function temperature(): float
    {
        return $this->temperature;
    }

    public function withAdditionalSystemPrompt(string $instruction): self
    {
        $normalizedInstruction = trim($instruction);
        if ($normalizedInstruction === '') {
            return $this;
        }

        return new self(
            $this->provider,
            $this->model,
            trim($this->systemPrompt."\n\n".$normalizedInstruction),
            $this->userMessage,
            $this->conversationHistory,
            $this->projectContext,
            $this->maxTokens,
            $this->temperature,
        );
    }

    public function withModel(string $model): self
    {
        return new self(
            $this->provider,
            $model,
            $this->systemPrompt,
            $this->userMessage,
            $this->conversationHistory,
            $this->projectContext,
            $this->maxTokens,
            $this->temperature,
        );
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function toOpenAIMessages(): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->systemPrompt,
            ],
            ...$this->conversationHistory,
            [
                'role' => 'user',
                'content' => $this->userMessage,
            ],
        ];
    }

    /**
     * @return list<array{role: string, content: list<array{type: string, text: string}>}>
     */
    public function toAnthropicMessages(): array
    {
        $messages = [];

        foreach ($this->conversationHistory as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => [[
                    'type' => 'text',
                    'text' => $message['content'],
                ]],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => $this->userMessage,
            ]],
        ];

        return $messages;
    }
}
