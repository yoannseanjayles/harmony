<?php

namespace App\Tests\Unit;

use App\Project\ProjectShareLinkGenerator;
use PHPUnit\Framework\TestCase;

final class ProjectShareLinkGeneratorTest extends TestCase
{
    public function testGeneratedTokenIsValidAgainstItsExpiry(): void
    {
        $generator = new ProjectShareLinkGenerator('share-secret', 3600);
        $issuedAt = new \DateTimeImmutable('2026-04-12 10:00:00');

        $link = $generator->generate($issuedAt);

        self::assertTrue($generator->isValid(
            $link['token'],
            $link['expiresAt'],
            new \DateTimeImmutable('2026-04-12 10:30:00'),
        ));
    }

    public function testValidationFailsForTamperedTokenOrExpiryMismatch(): void
    {
        $generator = new ProjectShareLinkGenerator('share-secret', 3600);
        $issuedAt = new \DateTimeImmutable('2026-04-12 10:00:00');

        $link = $generator->generate($issuedAt);
        $tamperedToken = $link['token'].'tampered';

        self::assertFalse($generator->isValid($tamperedToken, $link['expiresAt']));
        self::assertFalse($generator->isValid(
            $link['token'],
            $link['expiresAt']->modify('+1 hour'),
            new \DateTimeImmutable('2026-04-12 10:30:00'),
        ));
        self::assertFalse($generator->isValid(
            $link['token'],
            $link['expiresAt'],
            new \DateTimeImmutable('2026-04-12 12:30:00'),
        ));
    }
}
