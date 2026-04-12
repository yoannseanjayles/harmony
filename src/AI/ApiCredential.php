<?php

namespace App\AI;

final class ApiCredential implements \JsonSerializable
{
    public function __construct(
        private readonly string $provider,
        private readonly string $source,
        #[\SensitiveParameter]
        private readonly string $plainTextApiKey,
    ) {
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function reveal(): string
    {
        return $this->plainTextApiKey;
    }

    public function masked(): string
    {
        return '****'.substr($this->plainTextApiKey, -4);
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->provider,
            'source' => $this->source,
            'apiKey' => $this->masked(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
