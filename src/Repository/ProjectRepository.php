<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public const SCOPE_ACTIVE = 'active';
    public const SCOPE_ARCHIVED = 'archived';
    public const SCOPE_ALL = 'all';
    public const SORT_UPDATED = 'updated';
    public const SORT_NAME = 'name';
    public const SORT_STATUS = 'status';
    public const DIRECTION_ASC = 'asc';
    public const DIRECTION_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * @return list<Project>
     */
    public function findByOwnerScope(User $user, string $scope = self::SCOPE_ACTIVE): array
    {
        $queryBuilder = $this->createQueryBuilder('project')
            ->andWhere('project.user = :user')
            ->andWhere('project.status != :deleted')
            ->setParameter('user', $user)
            ->setParameter('deleted', Project::STATUS_DELETED)
            ->orderBy('project.updatedAt', 'DESC');

        if ($scope === self::SCOPE_ARCHIVED) {
            $queryBuilder->andWhere('project.archivedAt IS NOT NULL');
        } elseif ($scope === self::SCOPE_ACTIVE) {
            $queryBuilder->andWhere('project.archivedAt IS NULL');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return array{projects: list<Project>, total: int, page: int, totalPages: int}
     */
    public function findDashboardPage(
        User $user,
        string $scope = self::SCOPE_ACTIVE,
        string $search = '',
        string $sort = self::SORT_UPDATED,
        string $direction = self::DIRECTION_DESC,
        int $page = 1,
        int $perPage = 6,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $queryBuilder = $this->createDashboardQueryBuilder($user, $scope, $search);

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(project.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $this->applyDashboardSorting($queryBuilder, $sort, $direction);

        /** @var list<Project> $projects */
        $projects = $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'projects' => $projects,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ];
    }

    public function findOwnedProject(int $projectId, User $user): ?Project
    {
        return $this->createQueryBuilder('project')
            ->andWhere('project.id = :id')
            ->andWhere('project.user = :user')
            ->andWhere('project.status != :deleted')
            ->setParameter('id', $projectId)
            ->setParameter('user', $user)
            ->setParameter('deleted', Project::STATUS_DELETED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOwnedEditableProject(int $projectId, User $user): ?Project
    {
        return $this->createQueryBuilder('project')
            ->andWhere('project.id = :id')
            ->andWhere('project.user = :user')
            ->andWhere('project.status != :deleted')
            ->andWhere('project.archivedAt IS NULL')
            ->setParameter('id', $projectId)
            ->setParameter('user', $user)
            ->setParameter('deleted', Project::STATUS_DELETED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findSharedProjectByToken(string $token): ?Project
    {
        return $this->createQueryBuilder('project')
            ->andWhere('project.shareToken = :token')
            ->andWhere('project.isPublic = :isPublic')
            ->andWhere('project.status != :deleted')
            ->setParameter('token', $token)
            ->setParameter('isPublic', true)
            ->setParameter('deleted', Project::STATUS_DELETED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function createDashboardQueryBuilder(User $user, string $scope, string $search): \Doctrine\ORM\QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('project')
            ->andWhere('project.user = :user')
            ->andWhere('project.status != :deleted')
            ->setParameter('user', $user)
            ->setParameter('deleted', Project::STATUS_DELETED);

        if ($scope === self::SCOPE_ARCHIVED) {
            $queryBuilder->andWhere('project.archivedAt IS NOT NULL');
        } elseif ($scope === self::SCOPE_ACTIVE) {
            $queryBuilder->andWhere('project.archivedAt IS NULL');
        }

        $normalizedSearch = trim($search);
        if ($normalizedSearch !== '') {
            $queryBuilder
                ->andWhere('project.title LIKE :search OR project.provider LIKE :search OR project.model LIKE :search OR project.status LIKE :search')
                ->setParameter('search', '%'.$normalizedSearch.'%');
        }

        return $queryBuilder;
    }

    private function applyDashboardSorting(\Doctrine\ORM\QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $resolvedDirection = $direction === self::DIRECTION_ASC ? 'ASC' : 'DESC';

        $field = match ($sort) {
            self::SORT_NAME => 'project.title',
            self::SORT_STATUS => 'project.status',
            default => 'project.updatedAt',
        };

        $queryBuilder
            ->orderBy($field, $resolvedDirection)
            ->addOrderBy('project.updatedAt', 'DESC')
            ->addOrderBy('project.id', 'DESC');
    }
}
