<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Slide;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Slide>
 */
class SlideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Slide::class);
    }

    /**
     * @return list<Slide>
     */
    public function findByProjectOrdered(Project $project): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.project = :project')
            ->setParameter('project', $project)
            ->orderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
