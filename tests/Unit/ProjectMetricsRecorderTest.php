<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Entity\ProjectGenerationMetric;
use App\Project\ProjectMetricsRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * HRM-F40 T329 — Unit tests for ProjectMetricsRecorder generation recording.
 */
final class ProjectMetricsRecorderTest extends TestCase
{
    public function testRecordGenerationPersistsMetricWithAllFields(): void
    {
        $project = new Project();

        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function (object $obj) use (&$persisted): void {
                $persisted = $obj;
            });
        $entityManager->expects(self::once())->method('flush');

        $recorder = new ProjectMetricsRecorder($entityManager);
        $metric = $recorder->recordGeneration(
            project: $project,
            provider: 'openai',
            model: 'gpt-4.1-mini',
            estimatedCostUsd: 0.0042,
            slideCount: 5,
            durationMs: 1234,
            iterationCount: 3,
            errorCount: 1,
        );

        self::assertInstanceOf(ProjectGenerationMetric::class, $metric);
        self::assertSame($project, $metric->getProject());
        self::assertSame('openai', $metric->getProvider());
        self::assertSame('gpt-4.1-mini', $metric->getModel());
        self::assertEqualsWithDelta(0.0042, $metric->getEstimatedCostUsd(), 0.01);
        self::assertSame(5, $metric->getSlideCount());
        self::assertSame(1234, $metric->getDurationMs());
        self::assertSame(3, $metric->getIterationCount());
        self::assertSame(1, $metric->getErrorCount());
        // acceptedSlideCount is initialised to slideCount
        self::assertSame(5, $metric->getAcceptedSlideCount());
        self::assertSame($persisted, $metric);
    }

    public function testRecordGenerationUsesDefaultsForOptionalFields(): void
    {
        $project = new Project();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $recorder = new ProjectMetricsRecorder($entityManager);
        $metric = $recorder->recordGeneration(
            project: $project,
            provider: 'anthropic',
            model: 'claude-3-7-sonnet',
            estimatedCostUsd: 0.0,
        );

        self::assertSame(0, $metric->getSlideCount());
        self::assertNull($metric->getDurationMs());
        self::assertSame(1, $metric->getIterationCount());
        self::assertSame(0, $metric->getErrorCount());
    }

    public function testAcceptanceRateIsNullWhenNoSlides(): void
    {
        $project = new Project();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $recorder = new ProjectMetricsRecorder($entityManager);
        $metric = $recorder->recordGeneration(
            project: $project,
            provider: 'openai',
            model: 'gpt-4.1',
            estimatedCostUsd: 0.0,
            slideCount: 0,
        );

        self::assertNull($metric->getAcceptanceRate());
    }

    public function testAcceptanceRateIsOneWhenAllSlidesAccepted(): void
    {
        $project = new Project();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $recorder = new ProjectMetricsRecorder($entityManager);
        $metric = $recorder->recordGeneration(
            project: $project,
            provider: 'openai',
            model: 'gpt-4.1',
            estimatedCostUsd: 0.0,
            slideCount: 4,
        );

        self::assertEqualsWithDelta(1.0, $metric->getAcceptanceRate(), 0.0001);
    }
}
