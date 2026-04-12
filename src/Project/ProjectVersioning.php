<?php

namespace App\Project;

use App\Entity\Project;
use App\Entity\ProjectVersion;
use App\Repository\ProjectVersionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProjectVersioning
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectVersionRepository $projectVersionRepository,
        private readonly int $maxVersionsPerProject = 10,
    ) {
    }

    public function captureSnapshot(Project $project): ProjectVersion
    {
        $version = (new ProjectVersion())
            ->setProject($project)
            ->setVersionNumber($this->projectVersionRepository->nextVersionNumber($project))
            ->setSnapshot($project->toVersionSnapshot())
        ;

        $this->entityManager->persist($version);
        $this->entityManager->flush();

        $versionsToPrune = $this->projectVersionRepository->findVersionsToPrune($project, $this->maxVersionsPerProject);
        if ($versionsToPrune !== []) {
            foreach ($versionsToPrune as $staleVersion) {
                $this->entityManager->remove($staleVersion);
            }

            $this->entityManager->flush();
        }

        return $version;
    }

    public function restoreVersion(Project $project, ProjectVersion $version): ProjectVersion
    {
        $project->restoreFromVersionSnapshot($version->getSnapshot());
        $this->entityManager->flush();

        return $this->captureSnapshot($project);
    }

    /**
     * @return list<array{labelKey: string, before: string, after: string}>
     */
    public function describeDiffFromCurrent(ProjectVersion $version, Project $currentProject): array
    {
        $snapshot = $version->getSnapshot();
        $current = $currentProject->toVersionSnapshot();
        $diff = [];

        $this->appendScalarDiff($diff, 'project.history.field.title', $snapshot['title'] ?? '', $current['title'] ?? '');
        $this->appendScalarDiff($diff, 'project.history.field.provider', $snapshot['provider'] ?? '', $current['provider'] ?? '');
        $this->appendScalarDiff($diff, 'project.history.field.model', $snapshot['model'] ?? '', $current['model'] ?? '');
        $this->appendScalarDiff($diff, 'project.history.field.status', $snapshot['status'] ?? '', $current['status'] ?? '');
        $this->appendScalarDiff($diff, 'project.history.field.archived', (string) ($snapshot['archivedAt'] ?? ''), (string) ($current['archivedAt'] ?? ''));

        $this->appendCountDiff(
            $diff,
            'project.history.field.slides',
            is_array($snapshot['slides'] ?? null) ? count($snapshot['slides']) : 0,
            is_array($current['slides'] ?? null) ? count($current['slides']) : 0,
        );

        $this->appendCountDiff(
            $diff,
            'project.history.field.media',
            is_array($snapshot['mediaRefs'] ?? null) ? count($snapshot['mediaRefs']) : 0,
            is_array($current['mediaRefs'] ?? null) ? count($current['mediaRefs']) : 0,
        );

        if (($snapshot['themeConfig'] ?? []) !== ($current['themeConfig'] ?? [])) {
            $diff[] = [
                'labelKey' => 'project.history.field.theme',
                'before' => 'snapshot',
                'after' => 'current',
            ];
        }

        if (($snapshot['metadata'] ?? []) !== ($current['metadata'] ?? [])) {
            $diff[] = [
                'labelKey' => 'project.history.field.metadata',
                'before' => 'snapshot',
                'after' => 'current',
            ];
        }

        return $diff;
    }

    /**
     * @param list<array{labelKey: string, before: string, after: string}> $diff
     */
    private function appendScalarDiff(array &$diff, string $labelKey, mixed $before, mixed $after): void
    {
        if ((string) $before === (string) $after) {
            return;
        }

        $diff[] = [
            'labelKey' => $labelKey,
            'before' => (string) $before,
            'after' => (string) $after,
        ];
    }

    /**
     * @param list<array{labelKey: string, before: string, after: string}> $diff
     */
    private function appendCountDiff(array &$diff, string $labelKey, int $before, int $after): void
    {
        if ($before === $after) {
            return;
        }

        $diff[] = [
            'labelKey' => $labelKey,
            'before' => (string) $before,
            'after' => (string) $after,
        ];
    }
}
