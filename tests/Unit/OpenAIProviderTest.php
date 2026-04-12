<?php

namespace App\Tests\Unit;

use App\AI\ApiCredential;
use App\AI\OpenAIProvider;
use App\AI\PromptRequest;
use App\AI\Http\HttpResponse;
use App\Tests\Support\AI\RecordingAIHttpClient;
use PHPUnit\Framework\TestCase;

final class OpenAIProviderTest extends TestCase
{
    public function testSendPromptPostsExpectedPayloadAndParsesResponse(): void
    {
        $httpClient = new RecordingAIHttpClient(new HttpResponse(200, json_encode([
            'id' => 'chatcmpl_123',
            'model' => 'gpt-4.1',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Voici une reponse OpenAI.',
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 55,
                'completion_tokens' => 21,
            ],
        ], JSON_THROW_ON_ERROR)));

        $provider = new OpenAIProvider(
            $httpClient,
            new ApiCredential('openai', 'platform', 'sk-openai-1234'),
            'https://openai.test/v1',
        );

        $response = $provider->sendPrompt(new PromptRequest(
            'openai',
            'gpt-4.1',
            'System prompt',
            'Peux-tu resumer le projet ?',
            [['role' => 'assistant', 'content' => 'Contexte precedent']],
            ['title' => 'Projet'],
            900,
            0.2,
        ));

        self::assertSame('openai', $response->provider());
        self::assertSame('gpt-4.1', $response->model());
        self::assertSame('Voici une reponse OpenAI.', $response->content());
        self::assertSame(55, $response->inputTokens());
        self::assertSame(21, $response->outputTokens());
        self::assertCount(1, $httpClient->requests);
        self::assertSame('https://openai.test/v1/chat/completions', $httpClient->requests[0]['url']);
        self::assertSame('Bearer sk-openai-1234', $httpClient->requests[0]['headers']['Authorization']);
        self::assertSame('gpt-4.1', $httpClient->requests[0]['payload']['model']);
        self::assertSame('system', $httpClient->requests[0]['payload']['messages'][0]['role']);
        self::assertSame('System prompt', $httpClient->requests[0]['payload']['messages'][0]['content']);
        self::assertSame('assistant', $httpClient->requests[0]['payload']['messages'][1]['role']);
        self::assertSame('Peux-tu resumer le projet ?', $httpClient->requests[0]['payload']['messages'][2]['content']);
    }

    public function testStreamPromptEmitsNormalizedChunks(): void
    {
        $content = str_repeat('a', 260);
        $httpClient = new RecordingAIHttpClient(new HttpResponse(200, json_encode([
            'model' => 'gpt-4.1-mini',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
            ]],
        ], JSON_THROW_ON_ERROR)));

        $provider = new OpenAIProvider(
            $httpClient,
            new ApiCredential('openai', 'platform', 'sk-openai-1234'),
            'https://openai.test/v1',
        );

        $chunks = [];
        $response = $provider->streamPrompt(
            new PromptRequest('openai', 'gpt-4.1-mini', 'System prompt', 'Message utilisateur'),
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
        $provider = new OpenAIProvider(
            new RecordingAIHttpClient(new HttpResponse(200, '{}')),
            new ApiCredential('openai', 'platform', 'sk-openai-1234'),
        );

        self::assertSame(['gpt-4.1-mini', 'gpt-4.1'], $provider->getModelList());
    }
}
