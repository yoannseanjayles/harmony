<?php

namespace App\AI;

/**
 * Strategy contract for Harmony AI providers.
 *
 * Implementations must:
 * - send a normalized {@see PromptRequest} to a remote provider
 * - return a normalized {@see ProviderResponse}
 * - throw a RuntimeException when the upstream call fails or returns
 *   an unusable payload
 * - keep provider-specific transport/authentication details encapsulated
 */
interface AIProviderInterface
{
    /**
     * Sends the full prompt in a single request/response cycle.
     */
    public function sendPrompt(PromptRequest $promptRequest): ProviderResponse;

    /**
     * Streams provider output through normalized text chunks, then returns
     * the final normalized response once the upstream exchange is complete.
     *
     * @param callable(string): void $onChunk
     */
    public function streamPrompt(PromptRequest $promptRequest, callable $onChunk): ProviderResponse;

    /**
     * Returns the provider models supported by the current implementation.
     *
     * @return list<string>
     */
    public function getModelList(): array;
}
