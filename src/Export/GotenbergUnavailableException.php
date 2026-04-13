<?php

namespace App\Export;

/**
 * Thrown when the Gotenberg service returns an unexpected HTTP status (4xx) or is unreachable.
 */
final class GotenbergUnavailableException extends GotenbergException
{
    public function __construct(private readonly int $statusCode, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Gotenberg returned an unexpected HTTP status: %d.', $statusCode),
            'gotenberg_unavailable',
            $previous,
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
