<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reflex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reflex>
 */
class ReflexRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reflex::class);
    }

    /**
     * @return list<Reflex>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Enabled reflexes listening to this event.
     *
     * @return list<Reflex>
     */
    public function findTriggeredBy(string $source, string $type): array
    {
        /** @var list<Reflex> $reflexes */
        $reflexes = $this->createQueryBuilder('r')
            ->where('r.enabled = true')
            ->andWhere('r.triggerSource = :source')
            ->andWhere('r.triggerEvent IN (:events)')
            ->setParameter('source', $source)
            ->setParameter('events', [$type, Reflex::ANY_EVENT])
            ->getQuery()
            ->getResult();

        return $reflexes;
    }
}
