<?php

namespace App\AI;

use App\AI\Http\AIHttpClientInterface;

final class OpenAIProvider implements AIProviderInterface
{
    public function __construct(
        private readonly AIHttpClientInterface $httpClient,
        private readonly ApiCredential $apiCredential,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {
    }

    public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
    {
        $response = $this->httpClient->postJson(
            rtrim($this->baseUrl, '/').'/chat/completions',
            [
                'Authorization' => 'Bearer '.$this->apiCredential->reveal(),
            ],
            [
                'model' => $promptRequest->model(),
                'messages' => $promptRequest->toOpenAIMessages(),
                'temperature' => $promptRequest->temperature(),
                'max_tokens' => $promptRequest->maxTokens(),
            ],
        );

        $payload = $this->decodePayload($response->body());
        if ($response->statusCode() >= 400) {
            throw new \RuntimeException('OpenAI provider request failed.');
        }

        $content = $this->extractContent($payload);
        if ($content === '') {
            throw new \RuntimeException('OpenAI provider returned an empty response.');
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new ProviderResponse(
            'openai',
            (string) ($payload['model'] ?? $promptRequest->model()),
            $content,
            isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
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
            'gpt-4.1-mini',
            'gpt-4.1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('OpenAI provider returned invalid JSON.');
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractContent(array $payload): string
    {
        $choices = $payload['choices'] ?? [];
        if (!is_array($choices) || $choices === []) {
            return '';
        }

        $firstChoice = $choices[0] ?? [];
        if (!is_array($firstChoice)) {
            return '';
        }

        $message = $firstChoice['message'] ?? [];
        if (!is_array($message)) {
            return '';
        }

        $content = $message['content'] ?? '';
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
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
