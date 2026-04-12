<?php

namespace App\AI\Http;

interface AIHttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     */
    public function postJson(string $url, array $headers, array $payload): HttpResponse;
}
