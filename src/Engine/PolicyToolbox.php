<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Decision;
use App\Entity\DecisionKind;
use App\Entity\Run;
use App\Entity\RunStep;
use App\Entity\RunStepKind;
use App\Entity\ToolPolicy;
use App\Entity\Waitpoint;
use App\Entity\WaitpointKind;
use App\Repository\DecisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Applies a reflex's policy in code: forbidden tools don't exist for the agent, tools
 * that need approval only create a Decision, and every call is recorded on the run.
 * A call whose server asks for input pauses as a question for a person.
 *
 * Each call is stored before it runs, keyed by run, segment, ordinal, tool and arguments.
 * When a run resumes after a crash, a completed call returns its stored result instead of
 * running again, and an interrupted one runs again only if the tool is safe to repeat.
 */
final class PolicyToolbox implements ToolboxInterface
{
    public const string PROPOSAL_NOTICE = 'Needs human approval: calling it only records a proposal for a person to decide, it does not run.';
    /** Argument added to tools that need approval, so the agent says why. Never sent to the server. */
    public const string REASON_ARGUMENT = 'reflex_reason';

    /** @var array<string, array{tool: Tool, source: ConnectedToolbox, policy: ToolPolicy}>|null */
    private ?array $tools = null;

    private int $calls = 0;

    private ?Waitpoint $waitpoint = null;

    /**
     * @param iterable<ConnectedToolbox> $toolboxes
     */
    public function __construct(
        private readonly Run $run,
        private readonly iterable $toolboxes,
        private readonly EntityManagerInterface $entityManager,
        private readonly DecisionRepository $decisions,
        private readonly ?\DateTimeImmutable $deadline = null,
    ) {
    }

    public function getTools(): array
    {
        $tools = [];
        foreach ($this->tools() as ['tool' => $tool, 'policy' => $policy]) {
            $tools[] = ToolPolicy::Approval === $policy ? self::asProposal($tool) : $tool;
        }

        return $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (null !== $this->deadline && new \DateTimeImmutable() > $this->deadline) {
            throw new RunLimitExceededException('The run exceeded its time limit.');
        }

        $name = $toolCall->getName();
        $arguments = $toolCall->getArguments();
        $key = $this->idempotencyKey($name, $arguments);
        $previous = $this->run->findStep($key);
        $entry = $this->tools()[$name] ?? null;

        if (null === $entry) {
            $previous ?? $this->record($key, RunStepKind::Blocked, $name, $arguments, 'Not allowed by the policy.');

            return new ToolResult($toolCall, \sprintf('Tool "%s" is not available.', $name));
        }

        if (ToolPolicy::Approval === $entry['policy']) {
            if (null === $previous) {
                $this->propose($key, $entry['source'], $name, $arguments);
            }

            return new ToolResult($toolCall, 'Proposal recorded for a person to approve. It has NOT been executed. Do not call it again for the same purpose; you will be told the decision.');
        }

        if (null !== $previous && $previous->isCompleted()) {
            return new ToolResult($toolCall, $previous->getResult());
        }

        if (null !== $previous && !self::isSafeToRepeat($entry['tool'])) {
            $previous->fail('Interrupted by a crash; the outcome is unknown, so it was not run again.');
            $this->entityManager->flush();

            return new ToolResult($toolCall, 'This call was interrupted earlier and its outcome is unknown. It was not repeated; check the current state before acting again.');
        }

        $step = $previous ?? $this->record($key, RunStepKind::ToolCall, $name, $arguments);

        try {
            $result = $entry['source']->toolbox->execute($toolCall);
        } catch (\Throwable $e) {
            if (null !== $paused = $this->pauseForQuestion($entry['source'], $step, $toolCall)) {
                return $paused;
            }
            $step->fail($e->getMessage());
            $this->entityManager->flush();

            return new ToolResult($toolCall, 'The tool failed: '.$e->getMessage());
        }

        if (null !== $paused = $this->pauseForQuestion($entry['source'], $step, $toolCall)) {
            return $paused;
        }

        $step->complete(self::stringify($result->getResult()));
        $this->entityManager->flush();

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function idempotencyKeyFor(Run $run, int $ordinal, string $name, array $arguments): string
    {
        return hash('sha256', implode("\n", [
            $run->getId()->toRfc4122(),
            $run->getSegment(),
            $ordinal,
            $name,
            json_encode(self::canonical($arguments), \JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function propose(string $key, ConnectedToolbox $source, string $name, array $arguments): void
    {
        $reason = $arguments[self::REASON_ARGUMENT] ?? null;
        unset($arguments[self::REASON_ARGUMENT]);

        $this->waitpoint ??= $this->openWaitpoint();
        $this->record($key, RunStepKind::Proposal, $name, $arguments, \is_string($reason) ? $reason : null);

        $this->entityManager->persist(new Decision(
            $this->run,
            $this->waitpoint,
            $source->connection,
            $name,
            $source->remoteName($name),
            $arguments,
            \is_string($reason) && '' !== trim($reason) ? trim($reason) : 'No reason given.',
            requiredApprovals: $this->run->getReflex()->approvalsFor($name),
        ));
        $this->entityManager->flush();
    }

    /**
     * The server cancelled the call to ask something; it repeats once a person answers.
     */
    private function pauseForQuestion(ConnectedToolbox $source, RunStep $step, ToolCall $toolCall): ?ToolResult
    {
        $question = $source->questions?->takeAsked();
        if (null === $question) {
            return null;
        }

        $name = $toolCall->getName();
        $this->waitpoint ??= $this->openWaitpoint();
        $step->complete(\sprintf('Paused: the server asked "%s". Waiting for a person to answer.', $question->message));
        $this->entityManager->persist(new Decision(
            $this->run,
            $this->waitpoint,
            $source->connection,
            $name,
            $source->remoteName($name),
            $toolCall->getArguments(),
            'The server asked for input while running it.',
            DecisionKind::Question,
            question: $question,
        ));
        $this->entityManager->flush();

        return new ToolResult($toolCall, 'The server needs input from a person to finish this call, so it was paused. A person will answer and you will be told the outcome. Do not call it again for the same purpose.');
    }

    private function openWaitpoint(): Waitpoint
    {
        // A segment resumed after a crash already has its waitpoint.
        $open = $this->decisions->findOpenWaitpoint($this->run);
        if (null !== $open) {
            return $open;
        }

        $waitpoint = new Waitpoint($this->run, WaitpointKind::Decisions);
        $this->entityManager->persist($waitpoint);

        return $waitpoint;
    }

    /**
     * @return array<string, array{tool: Tool, source: ConnectedToolbox, policy: ToolPolicy}>
     */
    private function tools(): array
    {
        if (null !== $this->tools) {
            return $this->tools;
        }

        $reflex = $this->run->getReflex();
        $this->tools = [];
        foreach ($this->toolboxes as $source) {
            foreach ($source->toolbox->getTools() as $tool) {
                $policy = $reflex->policyFor($tool->getName(), $tool->getMetadata());
                if (ToolPolicy::Forbidden !== $policy) {
                    $this->tools[$tool->getName()] = ['tool' => $tool, 'source' => $source, 'policy' => $policy];
                }
            }
        }

        return $this->tools;
    }

    private static function asProposal(Tool $tool): Tool
    {
        $parameters = $tool->getParameters() ?? ['type' => 'object', 'properties' => []];
        $parameters['properties'][self::REASON_ARGUMENT] = [
            'type' => 'string',
            'description' => 'Why you propose this, for the person who decides. One or two sentences.',
        ];
        $parameters['required'] = array_values(array_unique([...($parameters['required'] ?? []), self::REASON_ARGUMENT]));

        // @phpstan-ignore argument.type (the remote tool's own schema plus one string property)
        return new Tool($tool->getReference(), $tool->getName(), self::PROPOSAL_NOTICE.' '.$tool->getDescription(), $parameters, $tool->getMetadata(), $tool->getEffectHints());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function idempotencyKey(string $name, array $arguments): string
    {
        return self::idempotencyKeyFor($this->run, ++$this->calls, $name, $arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function record(string $key, RunStepKind $kind, string $toolName, array $arguments, ?string $result = null): RunStep
    {
        $step = $this->run->addStep($key, $kind, $toolName, $arguments, $result);
        $this->entityManager->flush();

        return $step;
    }

    private static function isSafeToRepeat(Tool $tool): bool
    {
        return $tool->isReadOnly() || $tool->isIdempotent();
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    private static function stringify(mixed $result): string
    {
        return \is_string($result) ? $result : (string) json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
