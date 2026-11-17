<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\ConnectionStatus;
use App\Entity\Decision;
use App\Entity\ToolPolicy;
use App\Entity\Waitpoint;
use App\Mcp\McpClientFactory;
use App\Message\ExecuteDecision;
use App\Message\RunReflex;
use App\Repository\DecisionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Where people decide on proposals. An approved decision runs exactly as proposed,
 * without asking the model again; once a waitpoint has no open decision, its run resumes.
 */
final readonly class DecisionDesk
{
    public function __construct(
        #[Target('decision')]
        private WorkflowInterface $workflow,
        private EntityManagerInterface $entityManager,
        private DecisionRepository $decisions,
        private McpClientFactory $clientFactory,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function approve(Decision $decision, ?string $note = null): void
    {
        $decision->setNote($note);
        $this->workflow->apply($decision, 'approve');
        $this->entityManager->flush();

        $this->bus->dispatch(new ExecuteDecision($decision->getId()->toRfc4122()));
    }

    public function reject(Decision $decision, ?string $note = null): void
    {
        $decision->setNote($note);
        $this->workflow->apply($decision, 'reject');
        $this->entityManager->flush();

        $this->resolveIfDone($decision->getWaitpoint());
    }

    /**
     * Runs an approved decision once. A redelivered message after a crash fails it
     * instead of repeating a call whose outcome is unknown.
     */
    public function execute(Decision $decision): void
    {
        if (!$this->workflow->can($decision, 'execute')) {
            return;
        }

        if ($decision->hasExecutionStarted()) {
            $this->conclude($decision, 'fail', 'Interrupted while running; the outcome is unknown, so it was not run again.');

            return;
        }

        $decision->markExecutionStarted();
        $this->entityManager->flush();

        $connection = $decision->getConnection();
        $policy = $decision->getRun()->getReflex()->policyFor($decision->getToolName());
        if (ToolPolicy::Forbidden === $policy) {
            $this->conclude($decision, 'fail', 'The tool became forbidden after it was proposed.');

            return;
        }
        if (null === $connection || ConnectionStatus::Connected !== $connection->getStatus()) {
            $this->conclude($decision, 'fail', 'Its connection is gone or not connected.');

            return;
        }

        try {
            $client = $this->clientFactory->connect($connection);
            try {
                $result = $client->callTool($decision->getRemoteToolName(), $decision->getArguments());
            } finally {
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Decision {decision} failed.', ['decision' => $decision->getId()->toRfc4122(), 'exception' => $e]);
            $this->conclude($decision, 'fail', $e->getMessage());

            return;
        }

        $this->conclude($decision, $result->isError ? 'fail' : 'execute', self::describe($result));
    }

    private function conclude(Decision $decision, string $transition, string $result): void
    {
        $decision->setResult($result);
        $this->workflow->apply($decision, $transition);
        $this->entityManager->flush();

        $this->resolveIfDone($decision->getWaitpoint());
    }

    /**
     * Resumes the run once every decision of the waitpoint is resolved. The row lock keeps
     * two workers finishing the last decisions at once from resuming it twice.
     */
    private function resolveIfDone(Waitpoint $waitpoint): void
    {
        $resumed = $this->entityManager->wrapInTransaction(function () use ($waitpoint): bool {
            $this->entityManager->lock($waitpoint, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($waitpoint);

            foreach ($this->decisions->findByWaitpoint($waitpoint) as $decision) {
                $this->entityManager->refresh($decision);
                if (!$decision->isResolved()) {
                    return false;
                }
            }

            if ($waitpoint->isResolved()) {
                return false;
            }

            $waitpoint->resolve();
            $waitpoint->getRun()->resume();

            return true;
        });

        if ($resumed) {
            $this->bus->dispatch(new RunReflex($waitpoint->getRun()->getId()->toRfc4122()));
        }
    }

    private static function describe(CallToolResult $result): string
    {
        $texts = [];
        foreach ($result->content as $content) {
            $texts[] = $content instanceof TextContent && \is_string($content->text)
                ? $content->text
                : (string) json_encode($content, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $texts);
    }
}
