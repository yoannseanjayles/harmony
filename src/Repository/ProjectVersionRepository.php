<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectVersion>
 */
class ProjectVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectVersion::class);
    }

    public function nextVersionNumber(Project $project): int
    {
        $maxVersionNumber = $this->createQueryBuilder('project_version')
            ->select('MAX(project_version.versionNumber)')
            ->andWhere('project_version.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $maxVersionNumber) + 1;
    }

    /**
     * @return list<ProjectVersion>
     */
    public function findPaginatedByProject(Project $project, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('project_version')
            ->andWhere('project_version.project = :project')
            ->setParameter('project', $project)
            ->orderBy('project_version.versionNumber', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('project_version')
            ->select('COUNT(project_version.id)')
            ->andWhere('project_version.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOwnedVersion(Project $project, int $versionId): ?ProjectVersion
    {
        return $this->createQueryBuilder('project_version')
            ->andWhere('project_version.id = :id')
            ->andWhere('project_version.project = :project')
            ->setParameter('id', $versionId)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ProjectVersion>
     */
    public function findVersionsToPrune(Project $project, int $maxToKeep): array
    {
        return $this->createQueryBuilder('project_version')
            ->andWhere('project_version.project = :project')
            ->setParameter('project', $project)
            ->orderBy('project_version.versionNumber', 'DESC')
            ->setFirstResult(max(0, $maxToKeep))
            ->getQuery()
            ->getResult();
    }
}
