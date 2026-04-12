<?php

namespace App\Ops;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Operational logger for the harmony_ops Monolog channel.
 *
 * Covers HRM-T340 (AI retries), HRM-T341 (quota exceeded), HRM-T342 (export failures),
 * HRM-T343 (structured JSON), HRM-T344 (error-rate alerts), HRM-T345 (no PII / no API keys).
 */
class OpsLogger
{
    private const REDACTED = '[REDACTED]';

    /** Sensitive key fragments that must never appear in logs. */
    private const SENSITIVE_KEY_FRAGMENTS = ['password', 'api_key', 'apikey', 'secret', 'token', 'authorization'];

    /** Maximum number of attempts used by RetryPolicy (kept in sync with RetryPolicy). */
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        #[Autowire(service: 'monolog.logger.harmony_ops')]
        private readonly LoggerInterface $logger,
        private readonly float $aiErrorRateThreshold = 0.5,
    ) {
    }

    /**
     * Log an AI retry event.
     *
     * Covers HRM-T340 and HRM-T344.
     *
     * @param non-empty-string $reason One of: 'validation_failure' | 'provider_timeout'
     */
    public function logAiRetry(
        string $provider,
        string $model,
        int $attempt,
        string $reason,
    ): void {
        $context = $this->sanitize([
            'provider' => $provider,
            'model' => $model,
            'attempt' => $attempt,
            'maxAttempts' => self::MAX_ATTEMPTS,
            'reason' => $reason,
        ]);

        $this->logger->warning('ai_retry', $context);

        // HRM-T344 — alert when the per-request error rate exceeds the configurable threshold.
        $errorRate = $attempt / self::MAX_ATTEMPTS;
        if ($errorRate >= $this->aiErrorRateThreshold) {
            $this->logger->critical('ai_error_rate_threshold_exceeded', $this->sanitize([
                'provider' => $provider,
                'model' => $model,
                'errorRate' => $errorRate,
                'threshold' => $this->aiErrorRateThreshold,
            ]));
        }
    }

    /**
     * Log an AI quota exceedance.
     *
     * Covers HRM-T341.
     *
     * @param positive-int|null $userId Internal user identifier (never email or PII).
     */
    public function logQuotaExceeded(
        ?int $userId,
        string $provider,
        \DateTimeInterface $occurredAt,
    ): void {
        $this->logger->warning('ai_quota_exceeded', $this->sanitize([
            'userId' => $userId,
            'provider' => $provider,
            'occurredAt' => $occurredAt->format(\DateTimeInterface::ATOM),
        ]));
    }

    /**
     * Log an export failure.
     *
     * Covers HRM-T342.
     *
     * @param string $format     One of: 'html' | 'pdf'
     * @param string $reason     Human-readable reason (must not contain PII)
     * @param int    $durationMs Time elapsed in milliseconds before the failure
     */
    public function logExportFailure(string $format, string $reason, int $durationMs): void
    {
        $this->logger->error('export_failure', $this->sanitize([
            'format' => $format,
            'reason' => $reason,
            'durationMs' => $durationMs,
        ]));
    }

    /**
     * Recursively sanitize a context array.
     *
     * Covers HRM-T345 — removes sensitive keys and API-key-shaped strings.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $sanitized[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeStringValue($value);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeStringValue(string $value): string
    {
        // Redact OpenAI-style API keys (sk-…)
        if (preg_match('/\bsk-[A-Za-z0-9_-]+\b/', $value) === 1) {
            return self::REDACTED;
        }

        return $value;
    }
}
