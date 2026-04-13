<?php

namespace App\Export;

/**
 * Thrown when the Gotenberg service returns an unexpected HTTP status or is unreachable.
 */
final class GotenbergUnavailableException extends \RuntimeException
{
    public function __construct(int $statusCode, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Gotenberg returned an unexpected HTTP status: %d.', $statusCode),
            0,
            $previous,
        );
    }
}
