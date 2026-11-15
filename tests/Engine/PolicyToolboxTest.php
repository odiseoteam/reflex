<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\PolicyToolbox;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\RunStepKind;
use App\Entity\ToolPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PolicyToolboxTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Run $run;
    private FakeToolbox $tracker;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $reflex = new Reflex('Triage', 'generic', '*', 'Triage issues.');
        $reflex->setToolPolicies(['tracker_delete_issue' => ToolPolicy::Forbidden]);
        $this->run = new Run($reflex);
        $this->entityManager->persist($reflex);
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();

        $this->tracker = new FakeToolbox();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testHidesForbiddenToolsAndMarksProposals(): void
    {
        $tools = [];
        foreach ($this->toolbox()->getTools() as $tool) {
            $tools[$tool->getName()] = $tool;
        }

        self::assertSame(['tracker_list_issues', 'tracker_close_issue'], array_keys($tools));
        self::assertStringStartsWith(PolicyToolbox::PROPOSAL_NOTICE, $tools['tracker_close_issue']->getDescription());
        self::assertSame('List issues.', $tools['tracker_list_issues']->getDescription());
    }

    public function testRunsAutomaticToolsAndOnlyProposesTheOthers(): void
    {
        $toolbox = $this->toolbox();

        $toolbox->execute(new ToolCall('1', 'tracker_list_issues'));
        $proposal = $toolbox->execute(new ToolCall('2', 'tracker_close_issue', ['id' => 7]));
        $blocked = $toolbox->execute(new ToolCall('3', 'tracker_delete_issue', ['id' => 7]));

        self::assertSame(['tracker_list_issues'], $this->tracker->executed);
        self::assertIsString($proposal->getResult());
        self::assertStringContainsString('NOT been executed', $proposal->getResult());
        self::assertSame('Tool "tracker_delete_issue" is not available.', $blocked->getResult());

        $steps = $this->run->getSteps()->toArray();
        self::assertSame([RunStepKind::ToolCall, RunStepKind::Proposal, RunStepKind::Blocked], array_map(static fn ($step) => $step->getKind(), $steps));
        self::assertSame(['id' => 7], $steps[1]->getArguments());
    }

    public function testAnAgentCannotRunAToolThatNeedsApproval(): void
    {
        $turn = 0;
        $platform = MockPlatformFactory::createPlatform(static function (Model $model, mixed $input) use (&$turn): ToolCallResult|TextResult {
            return 0 === $turn++
                ? new ToolCallResult([new ToolCall('call-1', 'tracker_close_issue', ['id' => 7])])
                : new TextResult('Proposed closing issue 7.');
        });

        $agent = new Agent($platform, 'test-model', toolbox: $this->toolbox());
        $output = $agent->call('Issue 7 is a duplicate.')->asText();

        self::assertSame('Proposed closing issue 7.', $output);
        self::assertSame([], $this->tracker->executed);
        $step = $this->run->getSteps()->first();
        self::assertInstanceOf(\App\Entity\RunStep::class, $step);
        self::assertSame(RunStepKind::Proposal, $step->getKind());
    }

    private function toolbox(): PolicyToolbox
    {
        return new PolicyToolbox($this->run, [$this->tracker], $this->entityManager);
    }
}

/**
 * Stands in for an MCP server's toolbox.
 */
final class FakeToolbox implements ToolboxInterface
{
    /** @var list<string> */
    public array $executed = [];

    public function getTools(): array
    {
        $reference = new ExecutionReference(self::class);

        return [
            new Tool($reference, 'tracker_list_issues', 'List issues.', metadata: ['readOnlyHint' => true]),
            new Tool($reference, 'tracker_close_issue', 'Close an issue.', metadata: ['destructiveHint' => true]),
            new Tool($reference, 'tracker_delete_issue', 'Delete an issue.', metadata: ['destructiveHint' => true]),
        ];
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $this->executed[] = $toolCall->getName();

        return new ToolResult($toolCall, ['issues' => []]);
    }
}
