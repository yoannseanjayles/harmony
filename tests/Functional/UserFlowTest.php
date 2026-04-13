<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class UserFlowTest extends FunctionalTestCase
{
    public function testUserCanRegisterLoginAndLogout(): void
    {
        $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Creer mon compte', [
            'registration_form[email]' => 'lead@harmony.test',
            'registration_form[plainPassword][first]' => 'HarmonyFlow123',
            'registration_form[plainPassword][second]' => 'HarmonyFlow123',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.hm-alert--success', 'Votre compte a ete cree');

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'lead@harmony.test']);
        self::assertNotNull($user);
        self::assertNotSame('HarmonyFlow123', $user->getPassword());

        $this->client->submitForm('Se connecter', [
            'email' => 'lead@harmony.test',
            'password' => 'HarmonyFlow123',
            '_remember_me' => 'on',
        ]);

        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bienvenue dans Harmony');
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));

        $this->client->request('POST', '/logout', [
            '_csrf_token' => static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('logout')->getValue(),
        ]);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testRegistrationValidationMessagesAreLocalized(): void
    {
        $existingUser = (new User())
            ->setEmail('existing@harmony.test')
            ->setPassword(
                static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(
                    new User(),
                    'ExistingPass123',
                ),
            );

        $this->entityManager->persist($existingUser);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => 'existing@harmony.test',
            'registration_form[plainPassword][first]' => 'court',
            'registration_form[plainPassword][second]' => 'different',
        ]);

        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cette adresse e-mail est deja utilisee.');
        self::assertSelectorTextContains('body', 'Les deux mots de passe doivent etre identiques.');
    }
}
