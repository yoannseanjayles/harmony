<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectArchiveDuplicationTest extends FunctionalTestCase
{
    public function testOwnerCanDuplicateProjectAndKeepStructuredContentIntegrity(): void
    {
        $user = $this->createUser('duplication@harmony.test');
        $project = $this->createStructuredProject($user, 'Deck client');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();

        $duplicateForm = $crawler->filter(sprintf('form[action="/projects/%d/duplicate"]', $project->getId()))->form();
        $this->client->submit($duplicateForm);

        self::assertResponseRedirects();

        $projects = $this->entityManager->getRepository(Project::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $projects);

        $duplicated = $projects[1];
        self::assertNotSame($project->getId(), $duplicated->getId());
        self::assertSame('Copie de Deck client', $duplicated->getTitle());
        self::assertSame($project->getProvider(), $duplicated->getProvider());
        self::assertSame($project->getModel(), $duplicated->getModel());
        self::assertSame($project->getStatus(), $duplicated->getStatus());
        self::assertSame($project->getSlides(), $duplicated->getSlides());
        self::assertSame($project->getThemeConfig(), $duplicated->getThemeConfig());
        self::assertSame($project->getMetadata(), $duplicated->getMetadata());
        self::assertSame($project->getMediaRefs(), $duplicated->getMediaRefs());
        self::assertFalse($duplicated->isArchived());
    }

    public function testOwnerCanArchiveFilterAndRestoreProject(): void
    {
        $user = $this->createUser('archive@harmony.test');
        $project = $this->createStructuredProject($user, 'Roadmap archive');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();

        $archiveForm = $crawler->filter(sprintf('form[action="/projects/%d/archive"]', $project->getId()))->form();
        $this->client->submit($archiveForm);

        self::assertResponseRedirects('/projects?scope=archived');
        $project = $this->reloadProject($project->getId());
        self::assertTrue($project->isArchived());

        $this->client->request('GET', '/projects');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Roadmap archive', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/projects?scope=archived');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Roadmap archive', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Projet archive', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/projects/'.$project->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();

        $restoreForm = $crawler->filter(sprintf('form[action="/projects/%d/restore"]', $project->getId()))->form();
        $this->client->submit($restoreForm);

        self::assertResponseRedirects('/projects/'.$project->getId());
        $project = $this->reloadProject($project->getId());
        self::assertFalse($project->isArchived());

        $this->client->request('GET', '/projects');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Roadmap archive', (string) $this->client->getResponse()->getContent());
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

    private function createStructuredProject(User $user, string $title): Project
    {
        $project = (new Project())
            ->setTitle($title)
            ->setProvider('openai')
            ->setModel('gpt-4.1')
            ->setStatus(Project::STATUS_ACTIVE)
            ->setThemeConfig([
                'palette' => 'forest',
                'font' => 'Merriweather',
            ])
            ->setMetadata([
                'locale' => 'fr',
                'audience' => 'board',
            ])
            ->setSlides([
                ['id' => 'slide-1', 'title' => 'Intro', 'blocks' => [['type' => 'text', 'value' => 'Bonjour']]],
                ['id' => 'slide-2', 'title' => 'Plan', 'blocks' => [['type' => 'bullet', 'items' => ['A', 'B']]]],
            ])
            ->setMediaRefs([
                ['id' => 'media-1', 'path' => '/uploads/hero.png'],
                ['id' => 'media-2', 'path' => '/uploads/chart.svg'],
            ])
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function reloadProject(int $projectId): Project
    {
        $this->entityManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->clear();

        $project = $this->entityManager->getRepository(Project::class)->find($projectId);
        self::assertInstanceOf(Project::class, $project);

        return $project;
    }
}
