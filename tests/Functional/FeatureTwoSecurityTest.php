<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FeatureTwoSecurityTest extends FunctionalTestCase
{
    public function testMutableApiEndpointRequiresCsrfHeader(): void
    {
        $user = $this->createUser('csrf@harmony.test');
        $this->client->loginUser($user);

        $this->client->request('POST', '/api/preferences');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/preferences', server: [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken('api_mutation'),
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testLoginEndpointReturnsFormatted429AfterRepeatedFailures(): void
    {
        $this->createUser('login@harmony.test');

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $this->client->request('POST', '/login', [
                'email' => 'login@harmony.test',
                'password' => 'WrongPassword123',
                '_csrf_token' => $this->csrfToken('authenticate'),
            ]);
        }

        $this->client->request('POST', '/login', [
            'email' => 'login@harmony.test',
            'password' => 'WrongPassword123',
            '_csrf_token' => $this->csrfToken('authenticate'),
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testRegistrationEndpointIsRateLimitedByIp(): void
    {
        $this->client->request('POST', '/register', [
            'registration_form' => [
                'email' => 'first@harmony.test',
                'plainPassword' => [
                    'first' => 'RegisterPass123',
                    'second' => 'RegisterPass123',
                ],
                '_token' => $this->csrfToken('submit'),
            ],
        ]);

        self::assertResponseRedirects('/login');

        $this->client->request('POST', '/register', [
            'registration_form' => [
                'email' => 'second@harmony.test',
                'plainPassword' => [
                    'first' => 'RegisterPass123',
                    'second' => 'RegisterPass123',
                ],
                '_token' => $this->csrfToken('submit'),
            ],
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testAuthenticatedAiAndExportEndpointsAreRateLimitedPerUser(): void
    {
        $user = $this->createUser('limits@harmony.test');
        $this->client->loginUser($user);

        $headers = [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken('api_mutation'),
        ];

        $this->client->request('POST', '/ai/prompt', server: $headers);
        self::assertResponseStatusCodeSame(202);

        $this->client->request('POST', '/ai/prompt', server: $headers);
        self::assertResponseStatusCodeSame(429);

        $this->client->request('POST', '/export/html', server: $headers);
        self::assertResponseStatusCodeSame(202);

        $this->client->request('POST', '/export/html', server: $headers);
        self::assertResponseStatusCodeSame(429);
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

    private function csrfToken(string $tokenId): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($tokenId)->getValue();
    }
}
