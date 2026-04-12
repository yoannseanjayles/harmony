<?php

namespace App\Export;

/**
 * HRM-T272 — Thrown when the Gotenberg request exceeds the configured timeout.
 */
final class GotenbergTimeoutException extends GotenbergException
{
    public function __construct(\Throwable $previous = null)
    {
        parent::__construct(
            'Gotenberg request timed out.',
            'gotenberg_timeout',
            $previous,
        );
    }
}
