<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthGrant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthGrant>
 */
final class OAuthGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthGrant::class);
    }

    public function findByCode(string $code): ?OAuthGrant
    {
        return $this->findOneBy(['codeHash' => OAuthGrant::hash($code)]);
    }

    public function findByAccessToken(string $token): ?OAuthGrant
    {
        return $this->findOneBy(['accessHash' => OAuthGrant::hash($token)]);
    }

    /**
     * The grant of a current or an already rotated refresh token.
     *
     * @return array{OAuthGrant, bool}|null the grant, and whether the token was rotated away
     */
    public function findByRefreshToken(string $token): ?array
    {
        $hash = OAuthGrant::hash($token);
        if (null !== $grant = $this->findOneBy(['refreshHash' => $hash])) {
            return [$grant, false];
        }
        if (null !== $grant = $this->findOneBy(['previousRefreshHash' => $hash])) {
            return [$grant, true];
        }

        return null;
    }

    /**
     * @return list<OAuthGrant>
     */
    public function findActive(User $user, \DateTimeImmutable $now): array
    {
        $grants = $this->createQueryBuilder('g')
            ->join('g.client', 'c')->addSelect('c')
            ->where('g.user = :user')
            ->andWhere('g.revokedAt IS NULL')
            ->andWhere('g.codeUsedAt IS NOT NULL')
            ->andWhere('g.refreshExpiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($grants) ? $grants : [], static fn (mixed $grant): bool => $grant instanceof OAuthGrant));
    }
}
