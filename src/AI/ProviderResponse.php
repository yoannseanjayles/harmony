<?php

namespace App\AI;

final class ProviderResponse
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $content,
        private readonly ?int $inputTokens = null,
        private readonly ?int $outputTokens = null,
        private readonly array $rawPayload = [],
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

    public function content(): string
    {
        return $this->content;
    }

    public function inputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): ?int
    {
        return $this->outputTokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawPayload(): array
    {
        return $this->rawPayload;
    }
}
