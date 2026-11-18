<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\ConnectedToolbox;
use App\Engine\DecisionClosedException;
use App\Engine\DecisionDesk;
use App\Engine\PolicyToolbox;
use App\Engine\ReflexRunner;
use App\Engine\ToolboxProviderInterface;
use App\Entity\Decision;
use App\Entity\DecisionKind;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Entity\ToolPolicy;
use App\Entity\User;
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
 * A run proposes or gets asked something, pauses, people decide, and the run continues with the outcome.
 */
final class ApprovalLoopTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Reflex $reflex;
    private Run $run;
    private FakeToolbox $tracker;
    private User $alice;
    private User $bob;

    /** @var list<string> input the model received, per call */
    private array $seen = [];

    /** @var list<ToolCallResult|TextResult> what the model answers, in order */
    private array $script = [];

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->reflex = new Reflex('Triage', 'generic', '*', 'Close duplicates.');
        $event = new IncomingEvent('generic', 'delivery-1', 'issue.opened', ['title' => 'Same as #3']);
        $this->run = new Run($this->reflex, $event);
        $this->alice = new User('alice@example.com', 'Alice', true);
        $this->bob = new User('bob@example.com', 'Bob', true);
        foreach ([$this->reflex, $event, $this->run, $this->alice, $this->bob] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->tracker = new FakeToolbox();
        $this->script = [
            new ToolCallResult([new ToolCall('call-1', 'tracker_close_issue', ['id' => 7, PolicyToolbox::REASON_ARGUMENT => 'Duplicate of #3.'])]),
            new TextResult('I proposed closing #7.'),
            new TextResult('Understood, I left #7 open.'),
        ];
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

        $this->desk()->reject($decision, $this->alice, 'Not a duplicate, #3 is about another page.');

        self::assertSame(Decision::REJECTED, $decision->getStatus());
        self::assertSame(RunStatus::Queued, $this->run->getStatus());
        self::assertSame(2, $this->run->getSegment());
        self::assertCount(1, $this->sent(RunReflex::class));

        $this->runner()->run($this->run);

        self::assertSame(RunStatus::Succeeded, $this->run->getStatus());
        self::assertSame('Understood, I left #7 open.', $this->run->getOutput());
        self::assertStringContainsString('<decision kind=\"proposal\" status=\"rejected\"', $this->seen[2]);
        self::assertStringContainsString('Not a duplicate', $this->seen[2]);
    }

    public function testAnApprovalIsExecutedAsProposedAndFailsWithoutAConnection(): void
    {
        $this->runner()->run($this->run);
        $decision = $this->decisions()->findPending()[0];
        $desk = $this->desk();

        self::assertTrue($desk->approve($decision, $this->alice));
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

    public function testATwoPersonRuleWaitsForBothAndEachVotesOnce(): void
    {
        $this->reflex->setToolPolicies([], ['tracker_close_issue' => 2]);
        $this->runner()->run($this->run);
        $decision = $this->decisions()->findPending()[0];
        self::assertSame(2, $decision->getRequiredApprovals());

        self::assertFalse($this->desk()->approve($decision, $this->alice, 'Looks right.'));
        self::assertSame(Decision::PROPOSED, $decision->getStatus());
        self::assertSame([], $this->sent(ExecuteDecision::class));

        try {
            $this->desk()->approve($decision, $this->alice);
            self::fail('The same person approved twice.');
        } catch (DecisionClosedException) {
        }
        self::assertSame(1, $decision->countApprovals());

        self::assertTrue($this->desk()->approve($decision, $this->bob));
        self::assertSame(Decision::APPROVED, $decision->getStatus());
        self::assertCount(1, $this->sent(ExecuteDecision::class));
    }

    public function testAServerQuestionPausesTheRunUntilSomeoneAnswers(): void
    {
        $this->tracker = new FakeToolbox(askingTool: 'tracker_list_issues');
        $this->reflex->setToolPolicies(['tracker_list_issues' => ToolPolicy::Auto]);
        $this->script = [
            new ToolCallResult([new ToolCall('call-1', 'tracker_list_issues', ['state' => 'open'])]),
            new TextResult('Waiting for the label.'),
            new TextResult('Listing failed; I stopped.'),
        ];

        $this->runner()->run($this->run);

        self::assertSame(RunStatus::Waiting, $this->run->getStatus());
        $decision = $this->decisions()->findPending()[0];
        self::assertSame(DecisionKind::Question, $decision->getKind());
        self::assertSame(Decision::ASKED, $decision->getStatus());
        self::assertSame('Which label should it get?', $decision->getQuestion()?->message);
        self::assertSame(['state' => 'open'], $decision->getArguments());

        $this->desk()->answer($decision, $this->alice, ['label' => 'duplicate']);

        self::assertSame(Decision::APPROVED, $decision->getStatus());
        self::assertNull($decision->getQuestion());
        self::assertSame([['label' => 'duplicate']], array_values($decision->getAnswers()));
        self::assertCount(1, $this->sent(ExecuteDecision::class));

        $this->desk()->execute($decision);
        $this->runner()->run($this->run);

        self::assertSame(RunStatus::Succeeded, $this->run->getStatus());
        self::assertStringContainsString('<decision kind=\"question\" status=\"failed\"', $this->seen[2]);
    }

    public function testADeclinedQuestionTellsTheAgent(): void
    {
        $this->tracker = new FakeToolbox(askingTool: 'tracker_list_issues');
        $this->reflex->setToolPolicies(['tracker_list_issues' => ToolPolicy::Auto]);
        $this->script = [
            new ToolCallResult([new ToolCall('call-1', 'tracker_list_issues', [])]),
            new TextResult('Waiting.'),
            new TextResult('Declined, so I stopped.'),
        ];

        $this->runner()->run($this->run);
        $decision = $this->decisions()->findPending()[0];
        $this->desk()->decline($decision, $this->bob, 'Use no label.');

        self::assertSame(Decision::REJECTED, $decision->getStatus());
        self::assertSame($this->bob, $decision->getRespondent());

        $this->runner()->run($this->run);
        self::assertStringContainsString('<decision kind=\"question\" status=\"declined\"', $this->seen[2]);
        self::assertStringContainsString('Use no label.', $this->seen[2]);
    }

    private function runner(): ReflexRunner
    {
        $container = self::getContainer();
        $platform = MockPlatformFactory::createPlatform(function (Model $model, mixed $input): ToolCallResult|TextResult {
            $this->seen[] = json_encode($input, \JSON_UNESCAPED_SLASHES) ?: '';

            return $this->script[\count($this->seen) - 1] ?? new TextResult('Done.');
        });

        $serializer = $container->get('serializer');
        $toolboxes = new class($this->tracker) implements ToolboxProviderInterface {
            public function __construct(private FakeToolbox $tracker)
            {
            }

            public function open(Run $run): array
            {
                return [new ConnectedToolbox($this->tracker, null, $this->tracker->questions)];
            }

            public function close(): void
            {
            }
        };

        return new ReflexRunner($platform, $toolboxes, $this->entityManager, $container->get(RunRepository::class), $this->decisions(), $serializer, $serializer, new NullLogger());
    }

    private function desk(): DecisionDesk
    {
        return self::getContainer()->get(DecisionDesk::class);
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
