<?php

namespace App\Export;

/**
 * HRM-T272 — Thrown when Gotenberg returns an HTTP 5xx error response.
 */
final class GotenbergServerException extends GotenbergException
{
    public function __construct(private readonly int $statusCode, \Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Gotenberg returned HTTP %d.', $statusCode),
            'gotenberg_server_error',
            $previous,
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
