<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Decision;
use App\Entity\Run;
use App\Entity\Waitpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Decision>
 */
class DecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Decision::class);
    }

    /**
     * @return list<Decision>
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => Decision::PROPOSED], ['createdAt' => 'ASC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => Decision::PROPOSED]);
    }

    /**
     * @return list<Decision>
     */
    public function findRecentlyDecided(int $limit = 20): array
    {
        /** @var list<Decision> $decisions */
        $decisions = $this->createQueryBuilder('d')
            ->where('d.status != :proposed')
            ->setParameter('proposed', Decision::PROPOSED)
            ->orderBy('d.decidedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $decisions;
    }

    /**
     * @return list<Decision>
     */
    public function findByRun(Run $run): array
    {
        return $this->findBy(['run' => $run], ['createdAt' => 'ASC']);
    }

    /**
     * The run's unresolved waitpoint, if it is waiting on decisions.
     */
    public function findOpenWaitpoint(Run $run): ?Waitpoint
    {
        return $this->getEntityManager()->getRepository(Waitpoint::class)->findOneBy(['run' => $run, 'resolvedAt' => null]);
    }

    /**
     * @return list<Decision>
     */
    public function findByWaitpoint(Waitpoint $waitpoint): array
    {
        return $this->findBy(['waitpoint' => $waitpoint], ['createdAt' => 'ASC']);
    }
}
