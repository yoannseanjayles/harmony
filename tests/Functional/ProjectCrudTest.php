<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectVersionRepository;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectCrudTest extends FunctionalTestCase
{
    public function testProjectRoutesRequireAuthentication(): void
    {
        $this->client->request('GET', '/projects');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/projects/new');
        self::assertResponseRedirects('/login');
    }

    public function testOwnerCanCreateEditAndSoftDeleteProject(): void
    {
        $user = $this->createUser('projects-owner@harmony.test');
        $this->client->loginUser($user);

        $this->client->request('GET', '/projects/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Creer le projet', [
            'project[title]' => 'Roadmap IA',
            'project[provider]' => 'anthropic',
            'project[model]' => 'claude-sonnet-4-6',
            'project[status]' => Project::STATUS_ACTIVE,
        ]);

        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['title' => 'Roadmap IA']);
        self::assertInstanceOf(Project::class, $project);
        self::assertResponseRedirects('/projects/'.$project->getId());
        self::assertSame('anthropic', $project->getProvider());
        self::assertSame('claude-sonnet-4-6', $project->getModel());
        self::assertSame(Project::STATUS_ACTIVE, $project->getStatus());
        self::assertSame($user->getId(), $project->getUser()?->getId());
        self::assertSame(1, static::getContainer()->get(ProjectVersionRepository::class)->countByProject($project));

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Roadmap IA');
        self::assertSelectorTextContains('body', 'Anthropic');
        self::assertSelectorTextContains('body', 'Claude Sonnet 4.6');

        $this->client->request('GET', '/projects/'.$project->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Enregistrer les changements', [
            'project[title]' => 'Roadmap Produit',
            'project[provider]' => 'openai',
            'project[model]' => 'gpt-4.1',
            'project[status]' => Project::STATUS_DRAFT,
        ]);

        self::assertResponseRedirects('/projects/'.$project->getId());
        $this->entityManager->refresh($project);
        self::assertSame('Roadmap Produit', $project->getTitle());
        self::assertSame('openai', $project->getProvider());
        self::assertSame('gpt-4.1', $project->getModel());
        self::assertSame(Project::STATUS_DRAFT, $project->getStatus());
        self::assertSame(2, static::getContainer()->get(ProjectVersionRepository::class)->countByProject($project));

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Roadmap Produit');
        self::assertSelectorTextContains('body', 'OpenAI');
        self::assertSelectorTextContains('body', 'GPT-4.1');
        self::assertSelectorTextContains('body', 'Brouillon');

        $this->client->request('GET', '/projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Roadmap Produit');

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        $deleteForm = $crawler->filter(sprintf('form[action="/projects/%d/delete"]', $project->getId()))->form();
        $this->client->submit($deleteForm);

        self::assertResponseRedirects('/projects');
        $this->entityManager->refresh($project);
        self::assertTrue($project->isDeleted());
        self::assertSame(Project::STATUS_DELETED, $project->getStatus());

        $this->client->followRedirect();
        self::assertStringNotContainsString('Roadmap Produit', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testUserCannotAccessAnotherUsersProject(): void
    {
        $owner = $this->createUser('owner-project@harmony.test');
        $intruder = $this->createUser('intruder-project@harmony.test');
        $project = $this->createProject($owner, 'Projet prive');

        $this->client->loginUser($intruder);

        $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/projects/'.$project->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/projects/'.$project->getId().'/delete', [
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(404);
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

    private function createProject(User $user, string $title): Project
    {
        $project = (new Project())
            ->setTitle($title)
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_DRAFT)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
