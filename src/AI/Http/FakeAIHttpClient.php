<?php

namespace App\AI\Http;

final class FakeAIHttpClient implements AIHttpClientInterface
{
    public function postJson(string $url, array $headers, array $payload): HttpResponse
    {
        $model = (string) ($payload['model'] ?? 'fake-model');

        if (str_contains($url, 'anthropic')) {
            $userMessage = $this->extractAnthropicUserMessage($payload);

            return new HttpResponse(200, json_encode([
                'id' => 'msg_fake_123',
                'model' => $model,
                'content' => [[
                    'type' => 'text',
                    'text' => 'Reponse Harmony mock (Anthropic): '.$userMessage,
                ]],
                'usage' => [
                    'input_tokens' => 40,
                    'output_tokens' => 18,
                ],
            ], JSON_THROW_ON_ERROR));
        }

        $userMessage = $this->extractOpenAIUserMessage($payload);

        return new HttpResponse(200, json_encode([
            'id' => 'chatcmpl_fake_123',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Reponse Harmony mock (OpenAI): '.$userMessage,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 42,
                'completion_tokens' => 20,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractOpenAIUserMessage(array $payload): string
    {
        $messages = $payload['messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            return 'Message vide';
        }

        $lastMessage = end($messages);
        if (!is_array($lastMessage)) {
            return 'Message vide';
        }

        return (string) ($lastMessage['content'] ?? 'Message vide');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractAnthropicUserMessage(array $payload): string
    {
        $messages = $payload['messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            return 'Message vide';
        }

        $lastMessage = end($messages);
        if (!is_array($lastMessage)) {
            return 'Message vide';
        }

        $content = $lastMessage['content'] ?? [];
        if (!is_array($content) || $content === []) {
            return 'Message vide';
        }

        $firstBlock = $content[0] ?? [];
        if (!is_array($firstBlock)) {
            return 'Message vide';
        }

        return (string) ($firstBlock['text'] ?? 'Message vide');
    }
}
