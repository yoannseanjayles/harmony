<?php

namespace App\Export;

/**
 * HRM-T263 — Thrown when the Gotenberg service does not respond within the configured timeout.
 *
 * Callers (e.g. ExportController) catch this to return a graceful failure response to the user
 * instead of letting the generic RuntimeException bubble up as an HTTP 500.
 */
final class GotenbergTimeoutException extends \RuntimeException
{
    public function __construct(string $url, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Gotenberg request to "%s" timed out.', $url),
            0,
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
