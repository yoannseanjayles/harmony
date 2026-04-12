<?php

namespace App\Repository;

use App\Entity\Theme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Theme>
 */
class ThemeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Theme::class);
    }

    /**
     * @return list<Theme>
     */
    public function findAllPresets(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.isPreset = :isPreset')
            ->setParameter('isPreset', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
