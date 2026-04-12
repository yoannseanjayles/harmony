<?php

namespace App\AI;

final class EmptyAIResponseException extends \RuntimeException
{
    public function __construct(string $provider, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('AI provider "%s" returned an empty response.', $provider),
            0,
            $previous,
        );
    }
}
