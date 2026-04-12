<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\ProjectVersion;
use App\Entity\User;
use App\Project\ProjectVersioning;
use App\Repository\ProjectVersionRepository;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectVersionHistoryTest extends FunctionalTestCase
{
    public function testProjectVersionHistoryIsPaginatedAndSupportsRestore(): void
    {
        $user = $this->createUser('history@harmony.test');
        $project = $this->createStructuredProject($user, 'Deck v1');
        $versioning = static::getContainer()->get(ProjectVersioning::class);

        $versioning->captureSnapshot($project);

        for ($versionNumber = 2; $versionNumber <= 7; ++$versionNumber) {
            $project
                ->setTitle('Deck v'.$versionNumber)
                ->setSlides($this->buildSlides($versionNumber))
                ->setMediaRefs($this->buildMediaRefs($versionNumber))
            ;

            $this->entityManager->flush();
            $versioning->captureSnapshot($project);
        }

        $this->client->loginUser($user);

        $this->client->request('GET', '/projects/'.$project->getId().'/versions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Version 7', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Version 1', (string) $this->client->getResponse()->getContent());

        $versionRepository = static::getContainer()->get(ProjectVersionRepository::class);
        $firstVersion = $versionRepository->findOneBy([
            'project' => $this->reloadProject($project->getId()),
            'versionNumber' => 1,
        ]);
        self::assertInstanceOf(ProjectVersion::class, $firstVersion);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId().'/versions?page=2');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Version 1', (string) $this->client->getResponse()->getContent());

        $restoreForm = $crawler->filter(sprintf('form[action="/projects/%d/versions/%d/restore"]', $project->getId(), $firstVersion->getId()))->form();
        $this->client->submit($restoreForm);

        self::assertResponseRedirects('/projects/'.$project->getId());

        $project = $this->reloadProject($project->getId());
        self::assertSame('Deck v1', $project->getTitle());
        self::assertSame($this->buildMediaRefs(1), $project->getMediaRefs());
        self::assertSame($this->buildSlides(1), $project->getSlides());
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
            ->setSlides($this->buildSlides(1))
            ->setMediaRefs($this->buildMediaRefs(1))
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSlides(int $versionNumber): array
    {
        $slides = [];

        for ($index = 1; $index <= $versionNumber; ++$index) {
            $slides[] = [
                'id' => sprintf('slide-%d-%d', $versionNumber, $index),
                'title' => sprintf('Slide %d.%d', $versionNumber, $index),
            ];
        }

        return $slides;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildMediaRefs(int $versionNumber): array
    {
        $mediaRefs = [];

        for ($index = 1; $index <= max(1, min(3, $versionNumber)); ++$index) {
            $mediaRefs[] = [
                'id' => sprintf('media-%d-%d', $versionNumber, $index),
                'path' => sprintf('/uploads/%d-%d.png', $versionNumber, $index),
            ];
        }

        return $mediaRefs;
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
