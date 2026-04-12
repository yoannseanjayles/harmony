<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ApiKeyCipher
{
    public function __construct(
        #[Autowire('%env(string:APP_API_KEY_ENCRYPTION_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    public function encrypt(#[\SensitiveParameter] string $plainText): string
    {
        $key = $this->deriveKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = sodium_crypto_secretbox($plainText, $nonce, $key);

        return 'v1:'.base64_encode($nonce.$cipherText);
    }

    public function decrypt(#[\SensitiveParameter] string $payload): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new \RuntimeException('Unsupported API key payload version.');
        }

        $decoded = base64_decode(substr($payload, 3), true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid encrypted API key payload.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plainText = sodium_crypto_secretbox_open($cipherText, $nonce, $this->deriveKey());

        if ($plainText === false) {
            throw new \RuntimeException('Unable to decrypt API key payload.');
        }

        return $plainText;
    }

    public function mask(#[\SensitiveParameter] string $plainText): string
    {
        $visible = substr($plainText, -4);

        return '****'.$visible;
    }

    private function deriveKey(): string
    {
        return sodium_crypto_generichash(
            $this->secret,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        );
    }
}
