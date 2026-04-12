<?php

namespace App\AI;

use App\AI\Http\AIHttpClientInterface;

final class ClaudeProvider implements AIProviderInterface
{
    /**
     * HTTP timeout in seconds for Anthropic API requests.
     * Claude models can take longer for complex prompts; 60 s covers p99 latency.
     */
    public const TIMEOUT_SECONDS = 60.0;

    /**
     * Fast fallback model used when the primary model times out.
     */
    public const FALLBACK_MODEL = 'claude-3-5-sonnet';

    public function __construct(
        private readonly AIHttpClientInterface $httpClient,
        private readonly ApiCredential $apiCredential,
        private readonly string $baseUrl = 'https://api.anthropic.com/v1',
    ) {
    }

    public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
    {
        $response = $this->httpClient->postJson(
            rtrim($this->baseUrl, '/').'/messages',
            [
                'x-api-key' => $this->apiCredential->reveal(),
                'anthropic-version' => '2023-06-01',
            ],
            [
                'model' => $promptRequest->model(),
                'system' => $promptRequest->systemPrompt(),
                'messages' => $promptRequest->toAnthropicMessages(),
                'temperature' => $promptRequest->temperature(),
                'max_tokens' => $promptRequest->maxTokens(),
            ],
        );

        $payload = $this->decodePayload($response->body());
        if ($response->statusCode() >= 400) {
            throw new \RuntimeException('Anthropic provider request failed.');
        }

        $content = $this->extractContent($payload);
        if ($content === '') {
            throw new EmptyAIResponseException('anthropic');
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new ProviderResponse(
            'anthropic',
            (string) ($payload['model'] ?? $promptRequest->model()),
            $content,
            isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            $payload,
        );
    }

    public function streamPrompt(PromptRequest $promptRequest, callable $onChunk): ProviderResponse
    {
        $response = $this->sendPrompt($promptRequest);

        foreach ($this->chunkContent($response->content()) as $chunk) {
            $onChunk($chunk);
        }

        return $response;
    }

    public function getModelList(): array
    {
        return [
            'claude-3-7-sonnet',
            'claude-3-5-sonnet',
        ];
    }

    public function getFallbackModel(): string
    {
        return self::FALLBACK_MODEL;
    }

    public function getTimeoutSeconds(): float
    {
        return self::TIMEOUT_SECONDS;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Anthropic provider returned invalid JSON.');
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractContent(array $payload): string
    {
        $contentBlocks = $payload['content'] ?? [];
        if (!is_array($contentBlocks) || $contentBlocks === []) {
            return '';
        }

        $parts = [];
        foreach ($contentBlocks as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'text') {
                continue;
            }

            $parts[] = (string) ($block['text'] ?? '');
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * @return list<string>
     */
    private function chunkContent(string $content): array
    {
        $normalized = trim($content);
        if ($normalized === '') {
            return [];
        }

        return str_split($normalized, 120);
    }
}
