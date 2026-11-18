<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Decision;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\User;
use App\Entity\Vote;
use App\Entity\Waitpoint;
use App\Entity\WaitpointKind;
use App\Security\DecisionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class DecisionVoterTest extends TestCase
{
    public function testOnlyAdminsWhoHaveNotVotedDecide(): void
    {
        $run = new Run(new Reflex('Triage', 'generic', '*', 'Close duplicates.'), new IncomingEvent('generic', 'd-1', 'x', []));
        $decision = new Decision($run, new Waitpoint($run, WaitpointKind::Decisions), null, 'tracker_close_issue', 'close_issue', [], 'Why.', requiredApprovals: 2);
        $admin = new User('admin@example.com', 'Admin', true);
        $viewer = new User('viewer@example.com', 'Viewer');
        $voter = new DecisionVoter(new AccessDecisionManager([new RoleHierarchyVoter(new RoleHierarchy([User::ROLE_ADMIN => [User::ROLE_VIEWER]]))]));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), $decision, [DecisionVoter::DECIDE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($viewer), $decision, [DecisionVoter::DECIDE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($admin), $decision, [DecisionVoter::ANSWER]));

        $decision->addVote(new Vote($decision, $admin, true));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($admin), $decision, [DecisionVoter::DECIDE]));
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
