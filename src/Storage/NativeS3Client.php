<?php

namespace App\Storage;

/**
 * T219 — PHP-native S3 client using AWS Signature Version 4.
 *
 * Uses only built-in PHP functions (hash_hmac, file_get_contents with stream contexts)
 * so no additional dependencies are required.
 *
 * Compatible with AWS S3 and any S3-compatible service (MinIO, Cloudflare R2, etc.)
 * when a custom $endpoint is supplied.
 */
final class NativeS3Client implements S3ClientInterface
{
    private const SERVICE = 's3';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
        /** Optional custom endpoint for S3-compatible services (e.g. http://minio:9000) */
        private readonly string $endpoint = '',
    ) {
    }

    // ── StorageClientInterface ────────────────────────────────────────────────

    public function upload(string $bucket, string $key, string $filePath, string $contentType): void
    {
        $body = @file_get_contents($filePath);
        if ($body === false) {
            throw new \RuntimeException(sprintf('NativeS3Client: cannot read source file "%s".', $filePath));
        }

        $url  = $this->objectUrl($bucket, $key);
        $host = $this->host($bucket);
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $amzDate  = $now->format('Ymd\THis\Z');
        $dateShort = $now->format('Ymd');

        $payloadHash = hash('sha256', $body);

        $headers = [
            'host'                 => $host,
            'content-type'        => $contentType,
            'x-amz-date'          => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = $this->buildCanonicalHeaders($headers);

        $canonicalRequest = implode("\n", [
            'PUT',
            '/'.$key,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $authorization = $this->buildAuthorizationHeader(
            $canonicalRequest,
            $signedHeaders,
            $dateShort,
            $amzDate,
        );

        $headers['authorization'] = $authorization;

        $context = stream_context_create([
            'http' => [
                'method'        => 'PUT',
                'header'        => $this->formatHeaderLines($headers),
                'content'       => $body,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status   = $this->statusCode($http_response_header ?? []);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                sprintf('NativeS3Client: PUT %s/%s failed with HTTP %d.', $bucket, $key, $status),
            );
        }
    }

    public function download(string $bucket, string $key): string
    {
        $url  = $this->objectUrl($bucket, $key);
        $host = $this->host($bucket);
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $amzDate   = $now->format('Ymd\THis\Z');
        $dateShort = $now->format('Ymd');

        $emptyPayloadHash = hash('sha256', '');

        $headers = [
            'host'                 => $host,
            'x-amz-date'          => $amzDate,
            'x-amz-content-sha256' => $emptyPayloadHash,
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = $this->buildCanonicalHeaders($headers);

        $canonicalRequest = implode("\n", [
            'GET',
            '/'.$key,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $emptyPayloadHash,
        ]);

        $authorization = $this->buildAuthorizationHeader(
            $canonicalRequest,
            $signedHeaders,
            $dateShort,
            $amzDate,
        );

        $headers['authorization'] = $authorization;

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => $this->formatHeaderLines($headers),
                'ignore_errors' => true,
            ],
        ]);

        $body   = @file_get_contents($url, false, $context);
        $status = $this->statusCode($http_response_header ?? []);

        if ($status < 200 || $status >= 300 || $body === false) {
            throw new \RuntimeException(
                sprintf('NativeS3Client: GET %s/%s failed with HTTP %d.', $bucket, $key, $status),
            );
        }

        return $body;
    }

    public function delete(string $bucket, string $key): void
    {
        $url  = $this->objectUrl($bucket, $key);
        $host = $this->host($bucket);
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $amzDate   = $now->format('Ymd\THis\Z');
        $dateShort = $now->format('Ymd');

        $emptyPayloadHash = hash('sha256', '');

        $headers = [
            'host'                 => $host,
            'x-amz-date'          => $amzDate,
            'x-amz-content-sha256' => $emptyPayloadHash,
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = $this->buildCanonicalHeaders($headers);

        $canonicalRequest = implode("\n", [
            'DELETE',
            '/'.$key,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $emptyPayloadHash,
        ]);

        $authorization = $this->buildAuthorizationHeader(
            $canonicalRequest,
            $signedHeaders,
            $dateShort,
            $amzDate,
        );

        $headers['authorization'] = $authorization;

        $context = stream_context_create([
            'http' => [
                'method'        => 'DELETE',
                'header'        => $this->formatHeaderLines($headers),
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $context);
        $status = $this->statusCode($http_response_header ?? []);

        // 204 No Content and 404 Not Found are both acceptable outcomes.
        if ($status !== 204 && $status !== 200 && $status !== 404) {
            throw new \RuntimeException(
                sprintf('NativeS3Client: DELETE %s/%s failed with HTTP %d.', $bucket, $key, $status),
            );
        }
    }

    /**
     * Generate a pre-signed URL using AWS Signature V4 query-string authentication.
     */
    public function presign(string $bucket, string $key, int $expiresIn): string
    {
        $host      = $this->host($bucket);
        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate   = $now->format('Ymd\THis\Z');
        $dateShort = $now->format('Ymd');

        $scope = implode('/', [$dateShort, $this->region, self::SERVICE, 'aws4_request']);

        $queryParams = [
            'X-Amz-Algorithm'     => self::ALGORITHM,
            'X-Amz-Credential'    => $this->accessKeyId.'/'.$scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string) $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);

        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $canonicalHeaders     = 'host:'.$host."\n";
        $signedHeaders        = 'host';

        $canonicalRequest = implode("\n", [
            'GET',
            '/'.$key,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->deriveSigningKey($dateShort);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $queryParams['X-Amz-Signature'] = $signature;

        return $this->objectUrl($bucket, $key).'?'.http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function host(string $bucket): string
    {
        if ($this->endpoint !== '') {
            $parsed = parse_url($this->endpoint);

            return ($parsed['host'] ?? 'localhost').(isset($parsed['port']) ? ':'.$parsed['port'] : '');
        }

        return $bucket.'.s3.'.$this->region.'.amazonaws.com';
    }

    private function objectUrl(string $bucket, string $key): string
    {
        if ($this->endpoint !== '') {
            return rtrim($this->endpoint, '/').'/'.$bucket.'/'.$key;
        }

        return 'https://'.$bucket.'.s3.'.$this->region.'.amazonaws.com/'.$key;
    }

    /**
     * @param array<string, string> $headers Lowercase header name => value
     */
    private function buildCanonicalHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = strtolower($name).':'.trim($value);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, string> $headers
     */
    private function formatHeaderLines(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        return implode("\r\n", $lines);
    }

    private function buildAuthorizationHeader(
        string $canonicalRequest,
        string $signedHeaders,
        string $dateShort,
        string $amzDate,
    ): string {
        $scope = implode('/', [$dateShort, $this->region, self::SERVICE, 'aws4_request']);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->deriveSigningKey($dateShort);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        return sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKeyId,
            $scope,
            $signedHeaders,
            $signature,
        );
    }

    /**
     * Derive the AWS SigV4 signing key:
     *   HMAC(HMAC(HMAC(HMAC("AWS4" + secret, dateShort), region), service), "aws4_request")
     */
    private function deriveSigningKey(string $dateShort): string
    {
        $kDate    = hash_hmac('sha256', $dateShort, 'AWS4'.$this->secretAccessKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * @param list<string> $responseHeaders
     */
    private function statusCode(array $responseHeaders): int
    {
        $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 500';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return 500;
    }
}
