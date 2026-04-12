<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class UserApiKeyManager
{
    public function __construct(
        private readonly ApiKeyCipher $apiKeyCipher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function hasUserApiKey(User $user): bool
    {
        return $user->hasEncryptedApiKey();
    }

    public function rotateUserApiKey(User $user, #[\SensitiveParameter] string $plainTextApiKey): void
    {
        $normalizedKey = trim($plainTextApiKey);
        $user->setApiKeyEncrypted($this->apiKeyCipher->encrypt($normalizedKey));
        $this->entityManager->flush();

        sodium_memzero($normalizedKey);
    }

    public function removeUserApiKey(User $user): void
    {
        $user->setApiKeyEncrypted(null);
        $this->entityManager->flush();
    }

    public function revealUserApiKey(User $user): ?string
    {
        $payload = $user->getApiKeyEncrypted();
        if ($payload === null || $payload === '') {
            return null;
        }

        return $this->apiKeyCipher->decrypt($payload);
    }

    public function maskedUserApiKey(User $user): ?string
    {
        $plainText = $this->revealUserApiKey($user);
        if ($plainText === null) {
            return null;
        }

        $masked = $this->apiKeyCipher->mask($plainText);
        sodium_memzero($plainText);

        return $masked;
    }
}
