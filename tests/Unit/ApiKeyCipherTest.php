<?php

namespace App\Tests\Unit;

use App\Security\ApiKeyCipher;
use PHPUnit\Framework\TestCase;

final class ApiKeyCipherTest extends TestCase
{
    public function testEncryptThenDecryptReturnsOriginalValue(): void
    {
        $cipher = new ApiKeyCipher('unit-test-secret');
        $plainText = 'sk-unit-123456789';

        $encrypted = $cipher->encrypt($plainText);

        self::assertNotSame($plainText, $encrypted);
        self::assertSame($plainText, $cipher->decrypt($encrypted));
    }

    public function testEncryptionUsesRandomNonce(): void
    {
        $cipher = new ApiKeyCipher('unit-test-secret');
        $plainText = 'sk-unit-123456789';

        self::assertNotSame(
            $cipher->encrypt($plainText),
            $cipher->encrypt($plainText),
        );
    }

    public function testMaskReturnsOnlyLastFourCharacters(): void
    {
        $cipher = new ApiKeyCipher('unit-test-secret');

        self::assertSame('****CDEF', $cipher->mask('sk-live-ABCDEF'));
    }
}
