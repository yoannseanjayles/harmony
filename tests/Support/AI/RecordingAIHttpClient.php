<?php

namespace App\Tests\Support\AI;

use App\AI\Http\AIHttpClientInterface;
use App\AI\Http\HttpResponse;

final class RecordingAIHttpClient implements AIHttpClientInterface
{
    /**
     * @var list<array{url: string, headers: array<string, string>, payload: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @var list<HttpResponse>
     */
    private array $responses;

    public function __construct(HttpResponse ...$responses)
    {
        $this->responses = $responses;
    }

    public function postJson(string $url, array $headers, array $payload): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
        ];

        if ($this->responses === []) {
            throw new \RuntimeException('No fake AI HTTP response queued.');
        }

        /** @var HttpResponse $response */
        $response = array_shift($this->responses);

        return $response;
    }
}
