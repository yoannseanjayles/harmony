<?php

namespace App\Export;

/**
 * HRM-T259 — Contract for the Gotenberg PDF conversion client.
 *
 * The native implementation (NativeGotenbergClient) uses PHP stream contexts for HTTP.
 * A fake implementation can be injected in tests to avoid real network calls.
 */
interface GotenbergClientInterface
{
    /**
     * Convert a full HTML document to a PDF binary.
     *
     * @param string $html A complete, self-contained HTML document.
     *
     * @return string Raw PDF binary content.
     *
     * @throws GotenbergTimeoutException     When the request times out.
     * @throws GotenbergUnavailableException When Gotenberg returns a non-200 status.
     * @throws \RuntimeException             On any other transport failure.
     */
    public function convertHtmlToPdf(string $html): string;
}
