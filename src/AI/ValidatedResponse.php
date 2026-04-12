<?php

namespace App\AI;

final class ValidatedResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly bool $locallyRepaired,
    ) {
    }

    public function assistantMessage(): string
    {
        return (string) ($this->payload['assistant_message'] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(): array
    {
        $actions = $this->payload['actions'] ?? [];

        return is_array($actions) ? array_values(array_filter($actions, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function wasLocallyRepaired(): bool
    {
        return $this->locallyRepaired;
    }
}
