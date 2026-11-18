<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Precedent;
use App\Entity\Reflex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Precedent>
 */
final class PrecedentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Precedent::class);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Precedent> in the order of the ids, missing ones left out
     */
    public function findInOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $found = [];
        foreach ($this->findBy(['id' => $ids]) as $precedent) {
            $found[$precedent->getId()->toRfc4122()] = $precedent;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?Precedent => $found[$id] ?? null, $ids)));
    }

    /**
     * @return list<Precedent>
     */
    public function findLatest(Reflex $reflex, int $limit = 20): array
    {
        return $this->findBy(['reflex' => $reflex], ['decidedAt' => 'DESC'], $limit);
    }
}
