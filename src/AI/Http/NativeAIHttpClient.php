<?php

namespace App\AI\Http;

use App\AI\ProviderTimeoutException;

final class NativeAIHttpClient implements AIHttpClientInterface
{
    public function __construct(private readonly float $timeoutSeconds = 20.0)
    {
    }

    public function postJson(string $url, array $headers, array $payload): HttpResponse
    {
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $headerLines = ['Content-Type: application/json'];

        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $encodedPayload,
                'ignore_errors' => true,
                'timeout' => $this->timeoutSeconds,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $lastError = error_get_last();
            $errorMessage = strtolower((string) ($lastError['message'] ?? ''));
            if (str_contains($errorMessage, 'timed out') || str_contains($errorMessage, 'timeout')) {
                $provider = str_contains($url, 'anthropic') ? 'anthropic' : 'openai';
                throw new ProviderTimeoutException($provider);
            }

            throw new \RuntimeException('AI HTTP transport request failed.');
        }

        $responseHeaders = $this->normalizeHeaders($http_response_header ?? []);
        $statusCode = $this->extractStatusCode($http_response_header ?? []);

        return new HttpResponse($statusCode, $body, $responseHeaders);
    }

    /**
     * @param list<string> $rawHeaders
     *
     * @return array<string, string>
     */
    private function normalizeHeaders(array $rawHeaders): array
    {
        $headers = [];

        foreach ($rawHeaders as $headerLine) {
            if (!str_contains($headerLine, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $headerLine, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
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


    /**
     * @param list<string> $rawHeaders
     *
     * @return array<string, string>
     */
    private function normalizeHeaders(array $rawHeaders): array
    {
        $headers = [];

        foreach ($rawHeaders as $headerLine) {
            if (!str_contains($headerLine, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $headerLine, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
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
