<?php

namespace App\Export;

/**
 * HRM-T272 — Thrown when the TCP connection to Gotenberg is refused or the host is unreachable.
 */
final class GotenbergConnectionException extends GotenbergException
{
    public function __construct(\Throwable $previous = null)
    {
        parent::__construct(
            'Could not connect to Gotenberg.',
            'gotenberg_connection_refused',
            $previous,
        );
    }
}
