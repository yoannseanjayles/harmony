<?php

namespace App\Export;

/**
 * HRM-T259 / HRM-T260 / HRM-T261 / HRM-T263 — Native PHP HTTP client for the Gotenberg PDF service.
 *
 * Sends assembled HTML to Gotenberg's Chromium HTML-to-PDF route via a multipart/form-data
 * POST request and returns the raw PDF binary on success.
 *
 * Design decisions:
 *   - Uses only built-in PHP stream contexts (no extra dependencies) to match the project
 *     convention established by NativeAIHttpClient and NativeS3Client.
 *   - Timeout is configurable (GOTENBERG_TIMEOUT_SECONDS env var / DI parameter).
 *   - Throws GotenbergTimeoutException on timeout, GotenbergUnavailableException on non-200.
 *
 * Gotenberg Chromium route reference:
 *   POST /forms/chromium/convert/html
 *   multipart field: "index.html" → the full HTML document
 */
final class NativeGotenbergClient implements GotenbergClientInterface
{
    public function __construct(
        private readonly string $gotenbergUrl,
        private readonly float $timeoutSeconds = 30.0,
    ) {
    }

    public function convertHtmlToPdf(string $html): string
    {
        $endpoint = rtrim($this->gotenbergUrl, '/') . '/forms/chromium/convert/html';

        $boundary = '----HarmonyBoundary' . bin2hex(random_bytes(8));
        $body = $this->buildMultipartBody($boundary, $html);

        $context = stream_context_create([
            'http' => [
                'method'         => 'POST',
                'header'         => sprintf("Content-Type: multipart/form-data; boundary=%s\r\nContent-Length: %d", $boundary, strlen($body)),
                'content'        => $body,
                'ignore_errors'  => true,
                'timeout'        => $this->timeoutSeconds,
            ],
        ]);

        $responseBody = @file_get_contents($endpoint, false, $context);

        if ($responseBody === false) {
            $lastError = error_get_last();
            $errorMessage = strtolower((string) ($lastError['message'] ?? ''));

            if (str_contains($errorMessage, 'timed out') || str_contains($errorMessage, 'timeout')) {
                throw new GotenbergTimeoutException($endpoint);
            }

            throw new \RuntimeException(sprintf('Gotenberg HTTP request failed: %s', $lastError['message'] ?? 'unknown error'));
        }

        // HRM-T261 — Inspect status code; non-200 means Gotenberg could not convert
        /** @var list<string> $rawHeaders */
        $rawHeaders = $http_response_header ?? [];
        $statusCode = $this->extractStatusCode($rawHeaders);

        if ($statusCode !== 200) {
            throw new GotenbergUnavailableException($statusCode);
        }

        return $responseBody;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Build the multipart/form-data body with a single "index.html" file field.
     *
     * Gotenberg's Chromium route expects the HTML document under the "index.html" filename.
     */
    private function buildMultipartBody(string $boundary, string $html): string
    {
        $crlf = "\r\n";

        return '--' . $boundary . $crlf
             . 'Content-Disposition: form-data; name="files"; filename="index.html"' . $crlf
             . 'Content-Type: text/html; charset=UTF-8' . $crlf
             . $crlf
             . $html . $crlf
             . '--' . $boundary . '--' . $crlf;
    }

    /**
     * Extract the HTTP status code from raw response headers.
     *
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
