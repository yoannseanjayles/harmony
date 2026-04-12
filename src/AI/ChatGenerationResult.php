<?php

namespace App\AI;

final class ChatGenerationResult
{
    /**
     * @param list<array<string, mixed>> $slides
     * @param array<string, mixed>|null $pendingConfirmation
     */
    public function __construct(
        private readonly ProviderResponse $providerResponse,
        private readonly string $assistantMessage,
        private readonly array $slides,
        private readonly bool $slidesChanged,
        private readonly ?array $pendingConfirmation = null,
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

    /**
     * @return array<string, mixed>|null
     */
    public function pendingConfirmation(): ?array
    {
        return $this->pendingConfirmation;
    }

    public function requiresConfirmation(): bool
    {
        return $this->pendingConfirmation !== null;
    }
}
