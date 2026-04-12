<?php

namespace App\Project;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProjectShareLinkGenerator
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly int $ttlSeconds = 604800,
    ) {
    }

    /**
     * @return array{token: string, expiresAt: \DateTimeImmutable}
     */
    public function generate(?\DateTimeImmutable $issuedAt = null): array
    {
        $baseTime = $issuedAt ?? new \DateTimeImmutable();
        $expiresAt = $baseTime->modify(sprintf('+%d seconds', $this->ttlSeconds));
        $nonce = $this->base64UrlEncode(random_bytes(24));
        $expiresTimestamp = (string) $expiresAt->getTimestamp();
        $payload = $nonce.'.'.$expiresTimestamp;
        $signature = hash_hmac('sha256', $payload, $this->secret);

        return [
            'token' => $payload.'.'.$signature,
            'expiresAt' => $expiresAt,
        ];
    }

    public function isValid(string $token, ?\DateTimeImmutable $expectedExpiry = null, ?\DateTimeImmutable $now = null): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$nonce, $expiresTimestamp, $signature] = $parts;

        if ($nonce === '' || $expiresTimestamp === '' || $signature === '') {
            return false;
        }

        if (!ctype_digit($expiresTimestamp)) {
            return false;
        }

        $payload = $nonce.'.'.$expiresTimestamp;
        $expectedSignature = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $expiry = (new \DateTimeImmutable())->setTimestamp((int) $expiresTimestamp);
        $referenceTime = $now ?? new \DateTimeImmutable();

        if ($expiry < $referenceTime) {
            return false;
        }

        if ($expectedExpiry instanceof \DateTimeImmutable && $expectedExpiry->getTimestamp() !== $expiry->getTimestamp()) {
            return false;
        }

        return true;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
