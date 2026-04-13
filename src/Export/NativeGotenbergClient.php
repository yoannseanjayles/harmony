<?php

namespace App\Export;

/**
 * HRM-T259 / HRM-T260 / HRM-T261 / HRM-T267 / HRM-T272 â€” Native PHP HTTP client for the Gotenberg PDF service.
 *
 * Sends assembled HTML to Gotenberg's Chromium HTML-to-PDF route via a multipart/form-data
 * POST request and returns the raw PDF binary on success.
 *
 * Error classification (HRM-T272):
 *   - Connection failure  â†’ GotenbergConnectionException
 *   - Timeout             â†’ GotenbergTimeoutException
 *   - HTTP 5xx response   â†’ GotenbergServerException
 */
final class NativeGotenbergClient implements GotenbergClientInterface
{
    private const ENDPOINT_PATH = '/forms/chromium/convert/html';

    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds = 30.0,
    ) {
    }

    public function convertHtmlToPdf(string $htmlContent, string $filename): string
    {
        $boundary = '--HarmonyBoundary' . bin2hex(random_bytes(8));

        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"files\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: text/html\r\n"
            . "\r\n"
            . $htmlContent . "\r\n"
            . "--{$boundary}--\r\n";

        $url = rtrim($this->baseUrl, '/') . self::ENDPOINT_PATH;

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                    . 'Accept: application/pdf',
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => $this->timeoutSeconds,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $lastError    = error_get_last();
            $errorMessage = strtolower((string) ($lastError['message'] ?? ''));

            if (str_contains($errorMessage, 'timed out') || str_contains($errorMessage, 'timeout')) {
                throw new GotenbergTimeoutException();
            }

            throw new GotenbergConnectionException();
        }

        $statusCode = $this->extractStatusCode($http_response_header ?? []);

        if ($statusCode >= 500) {
            throw new GotenbergServerException($statusCode);
        }

        if ($statusCode >= 400) {
            throw new GotenbergServerException($statusCode);
        }

        return $response;
    }

    /**
     * @param list<string> $rawHeaders
     */
    private function extractStatusCode(array $rawHeaders): int
    {
        $statusLine = $rawHeaders[0] ?? 'HTTP/1.1 500';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return 500;
    }
}
