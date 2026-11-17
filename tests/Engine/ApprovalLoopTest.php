<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\ConnectedToolbox;
use App\Engine\DecisionDesk;
use App\Engine\PolicyToolbox;
use App\Engine\ReflexRunner;
use App\Engine\ToolboxProviderInterface;
use App\Entity\Decision;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Message\ExecuteDecision;
use App\Message\RunReflex;
use App\Repository\DecisionRepository;
use App\Repository\RunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A run proposes, pauses, a person decides, and the run continues with the outcome.
 */
final class ApprovalLoopTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Run $run;
    private FakeToolbox $tracker;

    /** @var list<string> input the model received, per call */
    private array $seen = [];

    private int $turn = 0;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $reflex = new Reflex('Triage', 'generic', '*', 'Close duplicates.');
        $event = new IncomingEvent('generic', 'delivery-1', 'issue.opened', ['title' => 'Same as #3']);
        $this->run = new Run($reflex, $event);
        foreach ([$reflex, $event, $this->run] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->tracker = new FakeToolbox();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testARejectionResumesTheRunWithTheNote(): void
    {
        $this->runner()->run($this->run);

        self::assertSame(RunStatus::Waiting, $this->run->getStatus());
        self::assertSame('I proposed closing #7.', $this->run->getOutput());
        $decision = $this->decisions()->findPending()[0];
        self::assertSame('Duplicate of #3.', $decision->getReason());
        self::assertSame([], $this->tracker->executed);

        self::getContainer()->get(DecisionDesk::class)->reject($decision, 'Not a duplicate, #3 is about another page.');

        self::assertSame(Decision::REJECTED, $decision->getStatus());
        self::assertSame(RunStatus::Queued, $this->run->getStatus());
        self::assertSame(2, $this->run->getSegment());
        self::assertCount(1, $this->sent(RunReflex::class));

        $this->runner()->run($this->run);

        self::assertSame(RunStatus::Succeeded, $this->run->getStatus());
        self::assertSame('Understood, I left #7 open.', $this->run->getOutput());
        self::assertStringContainsString('<decision status=\\"rejected\\"', $this->seen[2]);
        self::assertStringContainsString('Not a duplicate', $this->seen[2]);
    }

    public function testAnApprovalIsExecutedAsProposedAndFailsWithoutAConnection(): void
    {
        $this->runner()->run($this->run);
        $decision = $this->decisions()->findPending()[0];
        $desk = self::getContainer()->get(DecisionDesk::class);

        $desk->approve($decision);
        self::assertSame(Decision::APPROVED, $decision->getStatus());
        self::assertCount(1, $this->sent(ExecuteDecision::class));

        // The fake toolbox stands for no real connection, so execution can't reach a server.
        $desk->execute($decision);
        self::assertSame(Decision::FAILED, $decision->getStatus());
        self::assertSame('Its connection is gone or not connected.', $decision->getResult());
        self::assertSame(RunStatus::Queued, $this->run->getStatus());

        // A redelivered message changes nothing.
        $desk->execute($decision);
        self::assertSame(Decision::FAILED, $decision->getStatus());
    }

    private function runner(): ReflexRunner
    {
        $container = self::getContainer();
        $platform = MockPlatformFactory::createPlatform(function (Model $model, mixed $input): ToolCallResult|TextResult {
            $this->seen[] = json_encode($input, \JSON_UNESCAPED_SLASHES) ?: '';

            return match ($this->turn++) {
                0 => new ToolCallResult([new ToolCall('call-1', 'tracker_close_issue', ['id' => 7, PolicyToolbox::REASON_ARGUMENT => 'Duplicate of #3.'])]),
                1 => new TextResult('I proposed closing #7.'),
                default => new TextResult('Understood, I left #7 open.'),
            };
        });

        $serializer = $container->get('serializer');
        $toolboxes = new class($this->tracker) implements ToolboxProviderInterface {
            public function __construct(private FakeToolbox $tracker)
            {
            }

            public function open(Run $run): array
            {
                return [new ConnectedToolbox($this->tracker)];
            }

            public function close(): void
            {
            }
        };

        return new ReflexRunner($platform, $toolboxes, $this->entityManager, $container->get(RunRepository::class), $this->decisions(), $serializer, $serializer, new NullLogger());
    }

    private function decisions(): DecisionRepository
    {
        return self::getContainer()->get(DecisionRepository::class);
    }

    /**
     * @param class-string $class
     *
     * @return list<Envelope>
     */
    private function sent(string $class): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return array_values(array_filter($transport->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $class));
    }
}
