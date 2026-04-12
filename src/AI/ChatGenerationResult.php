<?php

namespace App\AI;

final class ChatGenerationResult
{
    /**
     * @param list<array<string, mixed>> $slides
     */
    public function __construct(
        private readonly ProviderResponse $providerResponse,
        private readonly string $assistantMessage,
        private readonly array $slides,
        private readonly bool $slidesChanged,
    ) {
    }

    public function providerResponse(): ProviderResponse
    {
        return $this->providerResponse;
    }

    public function assistantContent(): string
    {
        return $this->assistantMessage;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function slides(): array
    {
        return $this->slides;
    }

    public function slidesChanged(): bool
    {
        return $this->slidesChanged;
    }
}
