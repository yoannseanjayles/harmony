<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\SecurityLog;
use App\Entity\User;
use App\Project\ProjectShareLinkGenerator;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectShareLinkTest extends FunctionalTestCase
{
    public function testOwnerCanGenerateAccessAndRevokeSharedLink(): void
    {
        $user = $this->createUser('share@harmony.test');
        $project = $this->createStructuredProject($user, 'Deck partage');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();

        $generateForm = $crawler->filter(sprintf('form[action="/projects/%d/share/generate"]', $project->getId()))->form();
        $this->client->submit($generateForm);

        self::assertResponseRedirects('/projects/'.$project->getId());

        $project = $this->reloadProject($project->getId());
        self::assertTrue($project->isPublic());
        self::assertNotNull($project->getShareToken());
        self::assertNotNull($project->getShareExpiresAt());
        self::assertTrue($project->hasActiveShareLink());
        $sharedToken = $project->getShareToken();
        self::assertNotNull($sharedToken);

        $this->client->restart();
        $this->client->request('GET', '/share/'.$sharedToken);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Deck partage');
        self::assertSelectorTextContains('body', 'Partage lecture seule');
        self::assertStringNotContainsString('Modifier', (string) $this->client->getResponse()->getContent());

        $log = $this->reloadSecurityLog('shared_project_access');
        self::assertSame(hash('sha256', $sharedToken), $log->getPayload()['signatureHash']);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();

        $revokeForm = $crawler->filter(sprintf('form[action="/projects/%d/share/revoke"]', $project->getId()))->form();
        $this->client->submit($revokeForm);

        self::assertResponseRedirects('/projects/'.$project->getId());

        $project = $this->reloadProject($project->getId());
        self::assertFalse($project->isPublic());
        self::assertNull($project->getShareToken());
        self::assertNull($project->getShareExpiresAt());

        $this->client->request('GET', '/share/'.$sharedToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredSharedLinkReturnsGone(): void
    {
        $user = $this->createUser('expired-share@harmony.test');
        $project = $this->createStructuredProject($user, 'Deck expire');
        $shareLinkGenerator = static::getContainer()->get(ProjectShareLinkGenerator::class);
        $generatedLink = $shareLinkGenerator->generate(new \DateTimeImmutable('2026-04-12 10:00:00'));

        $project->activateShare($generatedLink['token'], new \DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $this->client->request('GET', '/share/'.$generatedLink['token']);
        self::assertResponseStatusCodeSame(410);
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
                ['id' => 'slide-1', 'title' => 'Intro'],
                ['id' => 'slide-2', 'title' => 'Plan'],
            ])
            ->setMediaRefs([
                ['id' => 'media-1', 'path' => '/uploads/hero.png'],
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

    private function reloadSecurityLog(string $eventType): SecurityLog
    {
        $this->entityManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->clear();

        $log = $this->entityManager->getRepository(SecurityLog::class)->findOneBy(['eventType' => $eventType]);
        self::assertInstanceOf(SecurityLog::class, $log);

        return $log;
    }
}
