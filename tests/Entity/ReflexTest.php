<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Reflex;
use App\Entity\ToolPolicy;
use PHPUnit\Framework\TestCase;

final class ReflexTest extends TestCase
{
    public function testDisablesAfterConsecutiveFailures(): void
    {
        $reflex = new Reflex('Triage', 'generic', '*', 'Triage.');

        self::assertFalse($reflex->recordFailure());
        $reflex->recordSuccess();
        self::assertFalse($reflex->recordFailure());
        self::assertFalse($reflex->recordFailure());
        self::assertTrue($reflex->recordFailure());

        self::assertFalse($reflex->isEnabled());
        self::assertSame('Disabled after 3 failed runs in a row.', $reflex->getDisabledReason());
    }

    public function testEnablingAgainClearsTheFailures(): void
    {
        $reflex = new Reflex('Triage', 'generic', '*', 'Triage.');
        for ($i = 0; $i < Reflex::FAILURE_THRESHOLD; ++$i) {
            $reflex->recordFailure();
        }

        $reflex->update('Triage', 'generic', '*', 'Triage.', Reflex::DEFAULT_MODEL, true, []);

        self::assertTrue($reflex->isEnabled());
        self::assertNull($reflex->getDisabledReason());
        self::assertSame(0, $reflex->getConsecutiveFailures());
    }

    public function testDefaultsToAutomaticOnlyForReadOnlyTools(): void
    {
        $reflex = new Reflex('Triage', 'generic', '*', 'Triage.');
        $reflex->setToolPolicies(['tracker_list' => ToolPolicy::Forbidden]);

        self::assertSame(ToolPolicy::Forbidden, $reflex->policyFor('tracker_list', ['readOnlyHint' => true]));
        self::assertSame(ToolPolicy::Auto, $reflex->policyFor('tracker_search', ['readOnlyHint' => true]));
        self::assertSame(ToolPolicy::Approval, $reflex->policyFor('tracker_close'));
    }
}
