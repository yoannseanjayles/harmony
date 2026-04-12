<?php

namespace App\AI;

final class ValidatedProviderResponse
{
    public function __construct(
        private readonly ProviderResponse $providerResponse,
        private readonly ValidatedResponse $validatedResponse,
        private readonly int $attemptCount,
    ) {
    }

    public function providerResponse(): ProviderResponse
    {
        return $this->providerResponse;
    }

    public function validatedResponse(): ValidatedResponse
    {
        return $this->validatedResponse;
    }

    public function assistantMessage(): string
    {
        return $this->validatedResponse->assistantMessage();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(): array
    {
        return $this->validatedResponse->actions();
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function wasLocallyRepaired(): bool
    {
        return $this->validatedResponse->wasLocallyRepaired();
    }
}
