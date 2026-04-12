<?php

namespace App\Tests\Unit;

use App\Entity\SecurityLog;
use App\Security\SecurityLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SecurityLoggerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private LoggerInterface&MockObject $logger;
    private ?SecurityLog $persistedLog = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->entityManager
            ->method('persist')
            ->willReturnCallback(function (SecurityLog $log): void {
                $this->persistedLog = $log;
            });
    }

    public function testAuthenticationFailureIsPersistedWithHashedIdentifier(): void
    {
        $securityLogger = $this->createLogger();

        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('authentication_failure', self::callback(function (array $context): bool {
                return $context['payload']['reason'] === 'unknown_account'
                    && isset($context['payload']['identifierHash']);
            }));

        $securityLogger->logAuthenticationFailure('lead@harmony.test', null, '127.0.0.1', 'unknown_account');

        self::assertSame('authentication_failure', $this->persistedLog?->getEventType());
        self::assertSame('127.0.0.1', $this->persistedLog?->getIpAddress());
        self::assertSame('unknown_account', $this->persistedLog?->getPayload()['reason']);
        self::assertArrayHasKey('identifierHash', $this->persistedLog?->getPayload());
    }

    public function testAiQuotaExceededStoresProviderAndModel(): void
    {
        $securityLogger = $this->createLogger();
        $this->logger->expects(self::once())->method('info');

        $securityLogger->logAiQuotaExceeded(42, '192.168.1.12', 'openai', 'gpt-4.1');

        self::assertSame('ai_quota_exceeded', $this->persistedLog?->getEventType());
        self::assertSame(42, $this->persistedLog?->getUserId());
        self::assertSame([
            'provider' => 'openai',
            'model' => 'gpt-4.1',
        ], $this->persistedLog?->getPayload());
    }

    public function testSharedProjectAccessStoresOnlyHashedSignature(): void
    {
        $securityLogger = $this->createLogger();
        $this->logger->expects(self::once())->method('info');

        $securityLogger->logSharedProjectAccess(null, '10.0.0.5', 'signed-token-value');

        self::assertSame('shared_project_access', $this->persistedLog?->getEventType());
        self::assertSame('10.0.0.5', $this->persistedLog?->getIpAddress());
        self::assertSame(hash('sha256', 'signed-token-value'), $this->persistedLog?->getPayload()['signatureHash']);
    }

    public function testSensitiveDataIsRedactedBeforePersistenceAndLogging(): void
    {
        $securityLogger = $this->createLogger();

        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('csrf_invalid', self::callback(function (array $context): bool {
                return $context['payload']['password'] === '[REDACTED]'
                    && $context['payload']['apiKey'] === '[REDACTED]'
                    && $context['payload']['nested']['authorization'] === '[REDACTED]';
            }));

        $securityLogger->logCsrfInvalid(7, '127.0.0.1', [
            'password' => 'SecretPassword123',
            'apiKey' => 'sk-live-123456',
            'nested' => [
                'authorization' => 'Bearer super-secret',
            ],
        ]);

        self::assertSame('csrf_invalid', $this->persistedLog?->getEventType());
        self::assertSame('[REDACTED]', $this->persistedLog?->getPayload()['password']);
        self::assertSame('[REDACTED]', $this->persistedLog?->getPayload()['apiKey']);
        self::assertSame('[REDACTED]', $this->persistedLog?->getPayload()['nested']['authorization']);
    }

    private function createLogger(): SecurityLogger
    {
        $this->entityManager->expects(self::once())->method('flush');

        return new SecurityLogger($this->entityManager, $this->logger);
    }
}
