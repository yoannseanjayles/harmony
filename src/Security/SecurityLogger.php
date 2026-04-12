<?php

namespace App\Security;

use App\Entity\SecurityLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SecurityLogger
{
    private const REDACTED = '[REDACTED]';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function log(string $eventType, ?int $userId, ?string $ipAddress, array $payload = []): SecurityLog
    {
        $sanitizedPayload = $this->sanitizePayload($payload);

        $log = (new SecurityLog())
            ->setEventType($eventType)
            ->setUserId($userId)
            ->setIpAddress($ipAddress)
            ->setPayload($sanitizedPayload);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->logger->info($eventType, [
            'eventType' => $eventType,
            'userId' => $userId,
            'ipAddress' => $ipAddress,
            'payload' => $sanitizedPayload,
        ]);

        return $log;
    }

    public function logAuthenticationFailure(?string $identifier, ?int $userId, ?string $ipAddress, string $reason): SecurityLog
    {
        return $this->log('authentication_failure', $userId, $ipAddress, [
            'reason' => $reason,
            'identifierHash' => $identifier ? hash('sha256', mb_strtolower(trim($identifier))) : null,
        ]);
    }

    public function logAiQuotaExceeded(?int $userId, ?string $ipAddress, string $provider, string $model): SecurityLog
    {
        return $this->log('ai_quota_exceeded', $userId, $ipAddress, [
            'provider' => $provider,
            'model' => $model,
        ]);
    }

    public function logSharedProjectAccess(?int $userId, ?string $ipAddress, string $signature): SecurityLog
    {
        return $this->log('shared_project_access', $userId, $ipAddress, [
            'signatureHash' => hash('sha256', $signature),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function logCsrfInvalid(?int $userId, ?string $ipAddress, array $payload = []): SecurityLog
    {
        return $this->log('csrf_invalid', $userId, $ipAddress, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $sanitized[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);

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
        foreach (['password', 'api_key', 'apikey', 'secret', 'token', 'authorization'] as $sensitiveWord) {
            if (str_contains($key, $sensitiveWord)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeStringValue(string $value): string
    {
        if (preg_match('/\bsk-[A-Za-z0-9_-]+\b/', $value) === 1) {
            return self::REDACTED;
        }

        return $value;
    }
}
