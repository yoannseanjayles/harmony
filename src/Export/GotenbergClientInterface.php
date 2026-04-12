<?php

namespace App\Export;

/**
 * HRM-T267 — Contract for the Gotenberg PDF conversion client.
 *
 * Implementations must delegate the actual HTTP call to the Gotenberg service.
 * Tests use FakeGotenbergClient; production uses NativeGotenbergClient.
 */
interface GotenbergClientInterface
{
    /**
     * Convert an HTML payload to a PDF via Gotenberg's Chromium endpoint.
     *
     * @param string $htmlContent Full HTML document to convert
     * @param string $filename    Name hint used for the multipart file field (e.g. "index.html")
     *
     * @return string Raw PDF bytes
     *
     * @throws GotenbergTimeoutException    when the request exceeds the configured timeout
     * @throws GotenbergServerException     when Gotenberg returns HTTP 5xx
     * @throws GotenbergConnectionException when the TCP connection cannot be established
     */
    public function convertHtmlToPdf(string $htmlContent, string $filename): string;
}
