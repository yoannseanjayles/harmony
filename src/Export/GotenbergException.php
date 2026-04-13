<?php

namespace App\Export;

/**
 * HRM-T267 — Base exception for all Gotenberg client failures.
 *
 * Sub-classes:
 *   - GotenbergTimeoutException    — request timed out
 *   - GotenbergServerException     — HTTP 5xx response from Gotenberg
 *   - GotenbergConnectionException — TCP connection refused / unreachable
 */
class GotenbergException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $errorCode, \Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Returns a machine-readable error code (e.g. "gotenberg_timeout").
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
