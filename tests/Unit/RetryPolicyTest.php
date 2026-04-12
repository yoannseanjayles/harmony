<?php

namespace App\Tests\Unit;

use App\AI\AIProviderInterface;
use App\AI\PromptRequest;
use App\AI\ProviderResponse;
use App\AI\ResponseSchema;
use App\AI\ResponseValidationException;
use App\AI\ResponseValidator;
use App\AI\RetryPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RetryPolicyTest extends TestCase
{
    public function testRetryOnceWhenFirstPayloadIsInvalidAndSecondIsCorrected(): void
    {
        $provider = new class(
            new ProviderResponse('openai', 'gpt-4.1', 'not-json'),
            new ProviderResponse('openai', 'gpt-4.1', json_encode([
                'assistant_message' => 'Payload corrige',
                'actions' => [],
            ], JSON_THROW_ON_ERROR)),
        ) implements AIProviderInterface {
            /** @var list<ProviderResponse> */
            private array $responses;

            /** @var list<PromptRequest> */
            public array $requests = [];

            public function __construct(ProviderResponse ...$responses)
            {
                $this->responses = $responses;
            }

            public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
            {
                $this->requests[] = $promptRequest;

                /** @var ProviderResponse $response */
                $response = array_shift($this->responses);

                return $response;
            }

            public function streamPrompt(PromptRequest $promptRequest, callable $onChunk): ProviderResponse
            {
                return $this->sendPrompt($promptRequest);
            }

            public function getModelList(): array
            {
                return ['gpt-4.1'];
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $retryPolicy = new RetryPolicy(
            new ResponseValidator(new ResponseSchema(), $logger),
            new ResponseSchema(),
            $logger,
        );

        $result = $retryPolicy->sendWithRetry(
            $provider,
            new PromptRequest('openai', 'gpt-4.1', 'System prompt', 'Message utilisateur'),
        );

        self::assertSame('Payload corrige', $result->assistantMessage());
        self::assertSame(2, $result->attemptCount());
        self::assertCount(2, $provider->requests);
        self::assertStringContainsString('Your previous answer was rejected', $provider->requests[1]->systemPrompt());
    }

    public function testLocalRepairAvoidsRetry(): void
    {
        $provider = new class(
            new ProviderResponse('openai', 'gpt-4.1', <<<JSON
```json
{"assistant_message":"Repare localement","actions":[]}
```
JSON),
        ) implements AIProviderInterface {
            /** @var list<ProviderResponse> */
            private array $responses;

            /** @var list<PromptRequest> */
            public array $requests = [];

            public function __construct(ProviderResponse ...$responses)
            {
                $this->responses = $responses;
            }

            public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
            {
                $this->requests[] = $promptRequest;

                /** @var ProviderResponse $response */
                $response = array_shift($this->responses);

                return $response;
            }

            public function streamPrompt(PromptRequest $promptRequest, callable $onChunk): ProviderResponse
            {
                return $this->sendPrompt($promptRequest);
            }

            public function getModelList(): array
            {
                return ['gpt-4.1'];
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $retryPolicy = new RetryPolicy(
            new ResponseValidator(new ResponseSchema(), $logger),
            new ResponseSchema(),
            $logger,
        );

        $result = $retryPolicy->sendWithRetry(
            $provider,
            new PromptRequest('openai', 'gpt-4.1', 'System prompt', 'Message utilisateur'),
        );

        self::assertSame('Repare localement', $result->assistantMessage());
        self::assertSame(1, $result->attemptCount());
        self::assertTrue($result->wasLocallyRepaired());
        self::assertCount(1, $provider->requests);
    }

    public function testRetryPolicyThrowsAfterSecondInvalidPayload(): void
    {
        $provider = new class(
            new ProviderResponse('openai', 'gpt-4.1', 'invalid-1'),
            new ProviderResponse('openai', 'gpt-4.1', 'invalid-2'),
        ) implements AIProviderInterface {
            /** @var list<ProviderResponse> */
            private array $responses;

            public function __construct(ProviderResponse ...$responses)
            {
                $this->responses = $responses;
            }

            public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
            {
                /** @var ProviderResponse $response */
                $response = array_shift($this->responses);

                return $response;
            }

            public function streamPrompt(PromptRequest $promptRequest, callable $onChunk): ProviderResponse
            {
                return $this->sendPrompt($promptRequest);
            }

            public function getModelList(): array
            {
                return ['gpt-4.1'];
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $retryPolicy = new RetryPolicy(
            new ResponseValidator(new ResponseSchema(), $logger),
            new ResponseSchema(),
            $logger,
        );

        $this->expectException(ResponseValidationException::class);

        $retryPolicy->sendWithRetry(
            $provider,
            new PromptRequest('openai', 'gpt-4.1', 'System prompt', 'Message utilisateur'),
        );
    }
}
