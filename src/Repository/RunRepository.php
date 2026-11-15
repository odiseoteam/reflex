<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Run;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Run>
 */
class RunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Run::class);
    }

    /**
     * @return list<Run>
     */
    public function findLatest(?\App\Entity\Reflex $reflex = null, int $limit = 50): array
    {
        return $this->findBy(null === $reflex ? [] : ['reflex' => $reflex], ['createdAt' => 'DESC'], $limit);
    }
}
