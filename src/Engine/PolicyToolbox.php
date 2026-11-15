<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Run;
use App\Entity\RunStepKind;
use App\Entity\ToolPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Applies a reflex's policy in code: forbidden tools don't exist for the agent,
 * tools that need approval are only proposed, and every call is recorded on the run.
 */
final class PolicyToolbox implements ToolboxInterface
{
    public const string PROPOSAL_NOTICE = 'Needs human approval: calling it only records a proposal for a person to decide, it does not run.';

    /** @var array<string, array{tool: Tool, toolbox: ToolboxInterface, policy: ToolPolicy}>|null */
    private ?array $tools = null;

    /**
     * @param iterable<ToolboxInterface> $toolboxes
     */
    public function __construct(
        private readonly Run $run,
        private readonly iterable $toolboxes,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getTools(): array
    {
        $tools = [];
        foreach ($this->tools() as ['tool' => $tool, 'policy' => $policy]) {
            $tools[] = ToolPolicy::Approval === $policy
                ? new Tool($tool->getReference(), $tool->getName(), self::PROPOSAL_NOTICE.' '.$tool->getDescription(), $tool->getParameters(), $tool->getMetadata(), $tool->getEffectHints())
                : $tool;
        }

        return $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $name = $toolCall->getName();
        $arguments = $toolCall->getArguments();
        $entry = $this->tools()[$name] ?? null;

        if (null === $entry) {
            $this->record(RunStepKind::Blocked, $name, $arguments, 'Not allowed by the policy.');

            return new ToolResult($toolCall, \sprintf('Tool "%s" is not available.', $name));
        }

        if (ToolPolicy::Approval === $entry['policy']) {
            $this->record(RunStepKind::Proposal, $name, $arguments);

            return new ToolResult($toolCall, 'Proposal recorded for a person to approve. It has NOT been executed. Do not call it again for the same purpose.');
        }

        try {
            $result = $entry['toolbox']->execute($toolCall);
        } catch (\Throwable $e) {
            $this->record(RunStepKind::Error, $name, $arguments, $e->getMessage());

            return new ToolResult($toolCall, 'The tool failed: '.$e->getMessage());
        }

        $this->record(RunStepKind::ToolCall, $name, $arguments, self::stringify($result->getResult()));

        return $result;
    }

    /**
     * @return array<string, array{tool: Tool, toolbox: ToolboxInterface, policy: ToolPolicy}>
     */
    private function tools(): array
    {
        if (null !== $this->tools) {
            return $this->tools;
        }

        $reflex = $this->run->getReflex();
        $this->tools = [];
        foreach ($this->toolboxes as $toolbox) {
            foreach ($toolbox->getTools() as $tool) {
                $policy = $reflex->policyFor($tool->getName(), $tool->getMetadata());
                if (ToolPolicy::Forbidden !== $policy) {
                    $this->tools[$tool->getName()] = ['tool' => $tool, 'toolbox' => $toolbox, 'policy' => $policy];
                }
            }
        }

        return $this->tools;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function record(RunStepKind $kind, string $toolName, array $arguments, ?string $result = null): void
    {
        $this->run->addStep($kind, $toolName, $arguments, $result);
        $this->entityManager->flush();
    }

    private static function stringify(mixed $result): string
    {
        return \is_string($result) ? $result : (string) json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
