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
     * Tokens the reflex spent since midnight UTC.
     */
    public function tokensUsedToday(\App\Entity\Reflex $reflex): int
    {
        $total = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.tokensUsed), 0)')
            ->where('r.reflex = :reflex')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('reflex', $reflex)
            ->setParameter('since', new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @return list<Run>
     */
    public function findLatest(?\App\Entity\Reflex $reflex = null, int $limit = 50): array
    {
        return $this->findBy(null === $reflex ? [] : ['reflex' => $reflex], ['createdAt' => 'DESC'], $limit);
    }
}
