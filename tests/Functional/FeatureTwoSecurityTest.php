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
        // First registration: use submitForm so the SameOriginCsrfTokenManager
        // double-submit cookie is set correctly by the browser client.
        $this->client->request('GET', '/register');
        $this->client->submitForm('Creer mon compte', [
            'registration_form[email]' => 'first@harmony.test',
            'registration_form[plainPassword][first]' => 'RegisterPass123',
            'registration_form[plainPassword][second]' => 'RegisterPass123',
        ]);

        self::assertResponseRedirects('/login');

        // Second registration: the rate limiter fires before CSRF / form validation.
        $this->client->request('POST', '/register', [
            'registration_form' => [
                'email' => 'second@harmony.test',
                'plainPassword' => [
                    'first' => 'RegisterPass123',
                    'second' => 'RegisterPass123',
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testAuthenticatedAiAndExportEndpointsAreRateLimitedPerUser(): void
    {
        $user = $this->createUser('limits@harmony.test');
        $this->client->loginUser($user);

        // Prime the Referer header (required for same-origin CSRF validation of stateless tokens)
        // and retrieve the api_mutation token value from the hm-csrf-api meta tag.
        $csrfToken = $this->apiCsrfToken();

        $headers = [
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
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

    /**
     * Makes a GET request to prime the BrowserKit Referer for subsequent POSTs and returns
     * the api_mutation CSRF token value embedded in the hm-csrf-api meta tag.
     * (SameOriginCsrfTokenManager validates stateless tokens via same-origin / Referer check.)
     */
    private function apiCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/dashboard');
        $meta = $crawler->filter('meta[name="hm-csrf-api"]');
        self::assertGreaterThan(0, $meta->count(), 'hm-csrf-api meta tag not found on dashboard');

        return (string) $meta->attr('content');
    }
}
