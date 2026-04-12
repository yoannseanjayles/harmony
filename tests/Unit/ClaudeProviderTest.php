<?php

namespace App\Tests\Unit;

use App\AI\ApiCredential;
use App\AI\ClaudeProvider;
use App\AI\Http\HttpResponse;
use App\AI\PromptRequest;
use App\Tests\Support\AI\RecordingAIHttpClient;
use PHPUnit\Framework\TestCase;

final class ClaudeProviderTest extends TestCase
{
    public function testSendPromptPostsExpectedPayloadAndParsesResponse(): void
    {
        $httpClient = new RecordingAIHttpClient(new HttpResponse(200, json_encode([
            'id' => 'msg_123',
            'model' => 'claude-3-7-sonnet',
            'content' => [[
                'type' => 'text',
                'text' => 'Voici une reponse Claude.',
            ]],
            'usage' => [
                'input_tokens' => 61,
                'output_tokens' => 24,
            ],
        ], JSON_THROW_ON_ERROR)));

        $provider = new ClaudeProvider(
            $httpClient,
            new ApiCredential('anthropic', 'platform', 'sk-anthropic-1234'),
            'https://anthropic.test/v1',
        );

        $response = $provider->sendPrompt(new PromptRequest(
            'anthropic',
            'claude-3-7-sonnet',
            'System prompt Claude',
            'Aide-moi a structurer ce deck.',
            [['role' => 'assistant', 'content' => 'Contexte Claude']],
            ['title' => 'Projet'],
            700,
            0.1,
        ));

        self::assertSame('anthropic', $response->provider());
        self::assertSame('claude-3-7-sonnet', $response->model());
        self::assertSame('Voici une reponse Claude.', $response->content());
        self::assertSame(61, $response->inputTokens());
        self::assertSame(24, $response->outputTokens());
        self::assertCount(1, $httpClient->requests);
        self::assertSame('https://anthropic.test/v1/messages', $httpClient->requests[0]['url']);
        self::assertSame('sk-anthropic-1234', $httpClient->requests[0]['headers']['x-api-key']);
        self::assertSame('2023-06-01', $httpClient->requests[0]['headers']['anthropic-version']);
        self::assertSame('claude-3-7-sonnet', $httpClient->requests[0]['payload']['model']);
        self::assertSame('System prompt Claude', $httpClient->requests[0]['payload']['system']);
        self::assertSame('assistant', $httpClient->requests[0]['payload']['messages'][0]['role']);
        self::assertSame('Aide-moi a structurer ce deck.', $httpClient->requests[0]['payload']['messages'][1]['content'][0]['text']);
    }

    public function testStreamPromptEmitsNormalizedChunks(): void
    {
        $content = str_repeat('b', 250);
        $httpClient = new RecordingAIHttpClient(new HttpResponse(200, json_encode([
            'model' => 'claude-3-5-sonnet',
            'content' => [[
                'type' => 'text',
                'text' => $content,
            ]],
        ], JSON_THROW_ON_ERROR)));

        $provider = new ClaudeProvider(
            $httpClient,
            new ApiCredential('anthropic', 'platform', 'sk-anthropic-1234'),
            'https://anthropic.test/v1',
        );

        $chunks = [];
        $response = $provider->streamPrompt(
            new PromptRequest('anthropic', 'claude-3-5-sonnet', 'System prompt', 'Message utilisateur'),
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        self::assertSame($content, $response->content());
        self::assertCount(3, $chunks);
        self::assertSame($content, implode('', $chunks));
    }

    public function testGetModelListReturnsSupportedModels(): void
    {
        $provider = new ClaudeProvider(
            new RecordingAIHttpClient(new HttpResponse(200, '{}')),
            new ApiCredential('anthropic', 'platform', 'sk-anthropic-1234'),
        );

        self::assertSame(['claude-3-7-sonnet', 'claude-3-5-sonnet'], $provider->getModelList());
    }

    public function testGetFallbackModelReturnsQuickModel(): void
    {
        $provider = new ClaudeProvider(
            new RecordingAIHttpClient(new HttpResponse(200, '{}')),
            new ApiCredential('anthropic', 'platform', 'sk-anthropic-1234'),
        );

        self::assertSame('claude-3-5-sonnet', $provider->getFallbackModel());
    }

    public function testGetTimeoutSecondsReturnsExplicitValue(): void
    {
        $provider = new ClaudeProvider(
            new RecordingAIHttpClient(new HttpResponse(200, '{}')),
            new ApiCredential('anthropic', 'platform', 'sk-anthropic-1234'),
        );

        self::assertSame(60.0, $provider->getTimeoutSeconds());
    }
}
