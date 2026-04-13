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
        private readonly int $attemptCount = 1,
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

    /** Number of provider call attempts (1 = no retry, 2 = one retry). */
    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    /** Error count: attempts that did not produce a valid response on first try. */
    public function errorCount(): int
    {
        return max(0, $this->attemptCount - 1);
    }
}
