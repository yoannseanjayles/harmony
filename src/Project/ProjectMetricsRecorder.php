<?php

namespace App\Project;

use App\Entity\Project;
use App\Entity\ProjectExportMetric;
use App\Entity\ProjectGenerationMetric;
use Doctrine\ORM\EntityManagerInterface;

final class ProjectMetricsRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function recordGeneration(Project $project, string $provider, string $model, string|float|int $estimatedCostUsd): ProjectGenerationMetric
    {
        $metric = (new ProjectGenerationMetric())
            ->setProject($project)
            ->setProvider($provider)
            ->setModel($model)
            ->setEstimatedCostUsd($estimatedCostUsd);

        $this->entityManager->persist($metric);
        $this->entityManager->flush();

        return $metric;
    }

    public function recordExport(Project $project, string $format, bool $wasSuccessful): ProjectExportMetric
    {
        $metric = (new ProjectExportMetric())
            ->setProject($project)
            ->setFormat($format)
            ->setWasSuccessful($wasSuccessful);

        $this->entityManager->persist($metric);
        $this->entityManager->flush();

        return $metric;
    }
}
