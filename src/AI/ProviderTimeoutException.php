<?php

namespace App\AI;

final class ProviderTimeoutException extends \RuntimeException
{
    public function __construct(string $provider, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('AI provider "%s" request timed out.', $provider),
            0,
            $previous,
        );
    }
}
