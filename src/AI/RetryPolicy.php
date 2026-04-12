<?php

namespace App\AI;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RetryPolicy
{
    public function __construct(
        private readonly ResponseValidator $responseValidator,
        private readonly ResponseSchema $responseSchema,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendWithRetry(AIProviderInterface $provider, PromptRequest $promptRequest): ValidatedProviderResponse
    {
        return $this->executeWithRetry($provider, $promptRequest, false);
    }

    public function streamWithRetry(AIProviderInterface $provider, PromptRequest $promptRequest): ValidatedProviderResponse
    {
        return $this->executeWithRetry($provider, $promptRequest, true);
    }

    private function executeWithRetry(AIProviderInterface $provider, PromptRequest $promptRequest, bool $stream): ValidatedProviderResponse
    {
        $attempt = 1;
        $currentPrompt = $promptRequest;
        $lastException = null;

        while ($attempt <= 2) {
            try {
                $providerResponse = $stream
                    ? $provider->streamPrompt($currentPrompt, static function (): void {})
                    : $provider->sendPrompt($currentPrompt);
            } catch (ProviderTimeoutException $exception) {
                $fallbackModel = $provider->getFallbackModel();
                $this->logger->warning('ai_provider_timeout_fallback', [
                    'attempt' => $attempt,
                    'originalModel' => $currentPrompt->model(),
                    'fallbackModel' => $fallbackModel,
                ]);

                if ($attempt === 2 || $currentPrompt->model() === $fallbackModel) {
                    throw $exception;
                }

                $currentPrompt = $currentPrompt
                    ->withModel($fallbackModel)
                    ->withAdditionalSystemPrompt($this->buildResumeInstruction($currentPrompt));

                ++$attempt;
                continue;
            }

            try {
                $validatedResponse = $this->responseValidator->validate($providerResponse->content());

                return new ValidatedProviderResponse($providerResponse, $validatedResponse, $attempt);
            } catch (ResponseValidationException $exception) {
                $lastException = $exception;
                $this->logger->warning('ai_response_retry_required', [
                    'attempt' => $attempt,
                    'errors' => $exception->errors(),
                    'payload' => $exception->rawContent(),
                ]);

                if ($attempt === 2) {
                    throw $exception;
                }

                $currentPrompt = $currentPrompt->withAdditionalSystemPrompt(
                    $this->buildCorrectionInstruction($exception),
                );
            }

            ++$attempt;
        }

        throw $lastException ?? new \RuntimeException('Retry policy failed without a validation exception.');
    }

    private function buildCorrectionInstruction(ResponseValidationException $exception): string
    {
        return implode("\n", [
            'Your previous answer was rejected by Harmony validation.',
            'Fix the JSON strictly and answer again with JSON only.',
            'Do not wrap the JSON in markdown fences.',
            'Do not invent unsupported actions or slide types.',
            'Validation errors:',
            '- '.implode("\n- ", $exception->errors()),
            'Schema reminder:',
            $this->responseSchema->promptInstructions(),
        ]);
    }

    private function buildResumeInstruction(PromptRequest $promptRequest): string
    {
        $slidesCount = (int) ($promptRequest->projectContext()['slidesCount'] ?? 0);

        return implode("\n", [
            'The previous attempt timed out. This is a retry using a faster model.',
            'Please provide a complete and valid JSON response.',
            $slidesCount > 0
                ? sprintf('The presentation currently has %d validated slide(s). Resume generation to complete the requested changes.', $slidesCount)
                : 'Start from the beginning and generate a complete response.',
        ]);
    }
}
