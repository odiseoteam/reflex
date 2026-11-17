<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\ReflexRunner;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\RunStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReflexRunnerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testSkipsRunsOnceTheDailyBudgetIsSpent(): void
    {
        $reflex = new Reflex('Triage', 'generic', '*', 'Triage.');
        $reflex->setLimits(1000, Reflex::DEFAULT_MAX_TOOL_CALLS, Reflex::DEFAULT_TIME_LIMIT);
        $spent = new Run($reflex);
        $spent->recordTokens(1200);
        $next = new Run($reflex);
        foreach ([$reflex, $spent, $next] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        self::getContainer()->get(ReflexRunner::class)->run($next);

        self::assertSame(RunStatus::Skipped, $next->getStatus());
        self::assertSame('Daily budget of 1000 tokens spent.', $next->getError());
        self::assertSame(0, $next->getAttempts());
    }

    public function testGivesUpOnARunThatKeepsCrashingTheWorker(): void
    {
        $reflex = new Reflex('Triage', 'generic', '*', 'Triage.');
        $run = new Run($reflex);
        for ($i = 0; $i < ReflexRunner::MAX_ATTEMPTS; ++$i) {
            $run->start();
        }
        $this->entityManager->persist($reflex);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        self::getContainer()->get(ReflexRunner::class)->run($run);

        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(1, $reflex->getConsecutiveFailures());
    }
}
