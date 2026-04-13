<?php

namespace App\Tests\Functional;

use App\AI\ProviderFactory;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileApiKeyTest extends FunctionalTestCase
{
    public function testUserCanSaveRotateAndDeleteByokApiKey(): void
    {
        $user = $this->createUser('profile@harmony.test');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune cle API BYOK');

        $this->client->submitForm('Enregistrer ma cle', [
            'profile_api_key[apiKey]' => 'sk-first-123456',
        ]);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '****3456');
        self::assertStringNotContainsString('sk-first-123456', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, $user->getId());
        self::assertNotNull($user);
        self::assertNotNull($user->getApiKeyEncrypted());
        self::assertNotSame('sk-first-123456', $user->getApiKeyEncrypted());

        $providerFactory = static::getContainer()->get(ProviderFactory::class);
        $credential = $providerFactory->resolveCredential($user);
        self::assertSame('byok', $credential->source());
        self::assertStringNotContainsString('sk-first-123456', json_encode($credential, JSON_THROW_ON_ERROR));

        $this->client->submitForm('Faire pivoter la cle', [
            'profile_api_key[apiKey]' => 'sk-second-654321',
        ]);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '****4321');
        self::assertStringNotContainsString('sk-second-654321', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('sk-first-123456', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/profile');
        $deleteForm = $crawler->filter('form[action="/profile/api-key/delete"]')->form();
        $this->client->submit($deleteForm);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Aucune cle API BYOK');

        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, $user->getId());
        self::assertNotNull($user);
        self::assertNull($user->getApiKeyEncrypted());
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword(
                static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(
                    new User(),
                    'ValidPassword123',
                ),
            );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
