<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Entity\ProjectVersion;
use App\Entity\User;
use App\Project\ProjectVersioning;
use App\Repository\ProjectVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProjectVersioningTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ProjectVersionRepository&MockObject $projectVersionRepository;
    private ?ProjectVersion $persistedVersion = null;
    /** @var list<ProjectVersion> */
    private array $removedVersions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->projectVersionRepository = $this->createMock(ProjectVersionRepository::class);

        $this->entityManager
            ->method('persist')
            ->willReturnCallback(function (ProjectVersion $version): void {
                $this->persistedVersion = $version;
            });

        $this->entityManager
            ->method('remove')
            ->willReturnCallback(function (ProjectVersion $version): void {
                $this->removedVersions[] = $version;
            });
    }

    public function testCaptureSnapshotCreatesSequentialVersionFromProjectState(): void
    {
        $project = $this->createProject('Deck board');
        $service = new ProjectVersioning($this->entityManager, $this->projectVersionRepository, 10);

        $this->projectVersionRepository
            ->expects(self::once())
            ->method('nextVersionNumber')
            ->with($project)
            ->willReturn(3);

        $this->projectVersionRepository
            ->expects(self::once())
            ->method('findVersionsToPrune')
            ->with($project, 10)
            ->willReturn([]);

        $this->entityManager->expects(self::once())->method('flush');

        $version = $service->captureSnapshot($project);

        self::assertSame($version, $this->persistedVersion);
        self::assertSame(3, $version->getVersionNumber());
        self::assertSame('Deck board', $version->getSnapshot()['title']);
        self::assertSame($project->getSlides(), $version->getSnapshot()['slides']);
        self::assertSame($project->getMediaRefs(), $version->getSnapshot()['mediaRefs']);
    }

    public function testCaptureSnapshotPrunesVersionsBeyondConfiguredLimit(): void
    {
        $project = $this->createProject('Deck retention');
        $oldestVersion = (new ProjectVersion())->setProject($project)->setVersionNumber(1);
        $olderVersion = (new ProjectVersion())->setProject($project)->setVersionNumber(2);

        $service = new ProjectVersioning($this->entityManager, $this->projectVersionRepository, 2);

        $this->projectVersionRepository->method('nextVersionNumber')->willReturn(3);
        $this->projectVersionRepository
            ->expects(self::once())
            ->method('findVersionsToPrune')
            ->with($project, 2)
            ->willReturn([$oldestVersion, $olderVersion]);

        $this->entityManager->expects(self::exactly(2))->method('flush');

        $service->captureSnapshot($project);

        self::assertCount(2, $this->removedVersions);
        self::assertSame([$oldestVersion, $olderVersion], $this->removedVersions);
    }

    public function testRestoreVersionReappliesSnapshotAndKeepsMediaReferences(): void
    {
        $project = $this->createProject('Etat courant');
        $project
            ->setTitle('Etat courant')
            ->setProvider('anthropic')
            ->setModel('claude-3-7-sonnet')
            ->setSlides([['id' => 'slide-live']])
            ->setMediaRefs([['id' => 'media-live', 'path' => '/uploads/live.png']])
            ->archive();

        $versionToRestore = (new ProjectVersion())
            ->setProject($project)
            ->setVersionNumber(2)
            ->setSnapshot([
                'title' => 'Etat initial',
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'status' => Project::STATUS_ACTIVE,
                'slides' => [['id' => 'slide-1'], ['id' => 'slide-2']],
                'themeConfig' => ['palette' => 'forest'],
                'metadata' => ['locale' => 'fr'],
                'mediaRefs' => [['id' => 'media-1', 'path' => '/uploads/hero.png']],
                'archivedAt' => null,
            ]);

        $service = new ProjectVersioning($this->entityManager, $this->projectVersionRepository, 10);

        $this->projectVersionRepository->method('nextVersionNumber')->willReturn(5);
        $this->projectVersionRepository->method('findVersionsToPrune')->willReturn([]);
        $this->entityManager->expects(self::exactly(2))->method('flush');

        $restoredVersion = $service->restoreVersion($project, $versionToRestore);

        self::assertSame('Etat initial', $project->getTitle());
        self::assertSame('openai', $project->getProvider());
        self::assertSame('gpt-4.1', $project->getModel());
        self::assertSame([['id' => 'media-1', 'path' => '/uploads/hero.png']], $project->getMediaRefs());
        self::assertFalse($project->isArchived());
        self::assertSame(5, $restoredVersion->getVersionNumber());
        self::assertSame($project->getMediaRefs(), $restoredVersion->getSnapshot()['mediaRefs']);
    }

    private function createProject(string $title): Project
    {
        return (new Project())
            ->setTitle($title)
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_DRAFT)
            ->setThemeConfig(['palette' => 'sand'])
            ->setMetadata(['owner' => 'lead'])
            ->setSlides([['id' => 'slide-1', 'title' => 'Intro']])
            ->setMediaRefs([['id' => 'media-1', 'path' => '/uploads/image.png']])
            ->setUser(new User());
    }
}
