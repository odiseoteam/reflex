<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Decision;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Admins decide on proposals and answer questions, each person voting once.
 *
 * @extends Voter<string, Decision>
 */
final class DecisionVoter extends Voter
{
    public const string DECIDE = 'DECISION_DECIDE';
    public const string ANSWER = 'DECISION_ANSWER';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::DECIDE, self::ANSWER], true) && $subject instanceof Decision;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$this->accessDecisionManager->decide($token, [User::ROLE_ADMIN])) {
            $vote?->addReason('Only admins decide.');

            return false;
        }

        return match ($attribute) {
            self::DECIDE => Decision::PROPOSED === $subject->getStatus() && !$subject->hasVoted($user),
            default => Decision::ASKED === $subject->getStatus(),
        };
    }
}
