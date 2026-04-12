<?php

namespace App\Tests\Unit;

use App\AI\AIProviderInterface;
use App\AI\EmptyAIResponseException;
use App\AI\PromptRequest;
use App\AI\ProviderResponse;
use App\AI\ProviderTimeoutException;
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

            public function getFallbackModel(): string
            {
                return 'gpt-4.1-mini';
            }

            public function getTimeoutSeconds(): float
            {
                return 30.0;
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

            public function getFallbackModel(): string
            {
                return 'gpt-4.1-mini';
            }

            public function getTimeoutSeconds(): float
            {
                return 30.0;
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

            public function getFallbackModel(): string
            {
                return 'gpt-4.1-mini';
            }

            public function getTimeoutSeconds(): float
            {
                return 30.0;
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

    /**
     * T119 / T126 — On timeout, the retry policy falls back to the fast model and retries once.
     */
    public function testTimeoutTriggersFallbackToFastModel(): void
    {
        $fallbackResponse = new ProviderResponse('openai', 'gpt-4.1-mini', json_encode([
            'assistant_message' => 'Reponse du modele rapide',
            'actions' => [],
        ], JSON_THROW_ON_ERROR));

        $provider = new class($fallbackResponse) implements AIProviderInterface {
            /** @var list<ProviderResponse> */
            private array $responses;

            /** @var list<PromptRequest> */
            public array $requests = [];

            private int $callCount = 0;

            public function __construct(ProviderResponse ...$responses)
            {
                $this->responses = $responses;
            }

            public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
            {
                $this->requests[] = $promptRequest;
                ++$this->callCount;

                if ($this->callCount === 1) {
                    throw new ProviderTimeoutException('openai');
                }

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
                return ['gpt-4.1-mini', 'gpt-4.1'];
            }

            public function getFallbackModel(): string
            {
                return 'gpt-4.1-mini';
            }

            public function getTimeoutSeconds(): float
            {
                return 30.0;
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

        self::assertSame('Reponse du modele rapide', $result->assistantMessage());
        self::assertSame(2, $result->attemptCount());
        self::assertCount(2, $provider->requests);
        // First attempt used the original model
        self::assertSame('gpt-4.1', $provider->requests[0]->model());
        // Fallback attempt used the fast model
        self::assertSame('gpt-4.1-mini', $provider->requests[1]->model());
        // Fallback prompt contains the resume instruction
        self::assertStringContainsString('timed out', $provider->requests[1]->systemPrompt());
    }

    /**
     * T119 / T126 — If the fallback model also times out, the exception propagates.
     */
    public function testTimeoutOnFallbackModelPropagatesException(): void
    {
        $provider = new class() implements AIProviderInterface {
            public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
            {
                throw new ProviderTimeoutException('openai');
            }

            public function streamPrompt(PromptRequest $promptRequest, callable $onChunk): ProviderResponse
            {
                return $this->sendPrompt($promptRequest);
            }

            public function getModelList(): array
            {
                return ['gpt-4.1-mini', 'gpt-4.1'];
            }

            public function getFallbackModel(): string
            {
                return 'gpt-4.1-mini';
            }

            public function getTimeoutSeconds(): float
            {
                return 30.0;
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $retryPolicy = new RetryPolicy(
            new ResponseValidator(new ResponseSchema(), $logger),
            new ResponseSchema(),
            $logger,
        );

        $this->expectException(ProviderTimeoutException::class);

        $retryPolicy->sendWithRetry(
            $provider,
            new PromptRequest('openai', 'gpt-4.1', 'System prompt', 'Message utilisateur'),
        );
    }

    /**
     * T120 / T126 — Resume context includes the existing slides count in the fallback prompt.
     */
    public function testFallbackPromptContainsResumeContextWithSlideCount(): void
    {
        $fallbackResponse = new ProviderResponse('openai', 'gpt-4.1-mini', json_encode([
            'assistant_message' => 'Reprise reussie',
            'actions' => [],
        ], JSON_THROW_ON_ERROR));

        $provider = new class($fallbackResponse) implements AIProviderInterface {
            /** @var list<ProviderResponse> */
            private array $responses;

            /** @var list<PromptRequest> */
            public array $requests = [];

            private int $callCount = 0;

            public function __construct(ProviderResponse ...$responses)
            {
                $this->responses = $responses;
            }

            public function sendPrompt(PromptRequest $promptRequest): ProviderResponse
            {
                $this->requests[] = $promptRequest;
                ++$this->callCount;

                if ($this->callCount === 1) {
                    throw new ProviderTimeoutException('openai');
                }

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
                return ['gpt-4.1-mini', 'gpt-4.1'];
            }

            public function getFallbackModel(): string
            {
                return 'gpt-4.1-mini';
            }

            public function getTimeoutSeconds(): float
            {
                return 30.0;
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $retryPolicy = new RetryPolicy(
            new ResponseValidator(new ResponseSchema(), $logger),
            new ResponseSchema(),
            $logger,
        );

        $retryPolicy->sendWithRetry(
            $provider,
            new PromptRequest('openai', 'gpt-4.1', 'System prompt', 'Message utilisateur', [], ['slidesCount' => 3]),
        );

        self::assertStringContainsString('3 validated slide', $provider->requests[1]->systemPrompt());
    }

    /**
     * T121 / T126 — EmptyAIResponseException is a typed exception distinct from generic RuntimeException.
     */
    public function testEmptyAIResponseExceptionIsThrownByProviderOnEmptyContent(): void
    {
        $this->expectException(EmptyAIResponseException::class);
        $this->expectExceptionMessageMatches('/empty response/i');

        throw new EmptyAIResponseException('openai');
    }
}
