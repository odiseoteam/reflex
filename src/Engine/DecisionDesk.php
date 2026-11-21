<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\ConnectionStatus;
use App\Entity\Decision;
use App\Entity\ToolPolicy;
use App\Entity\User;
use App\Entity\Verdict;
use App\Entity\Vote;
use App\Entity\Waitpoint;
use App\Mcp\Elicitation\QuestionBox;
use App\Mcp\McpClientFactory;
use App\Memory\Memory;
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
 * Where people decide on proposals and answer servers' questions. An approved decision
 * runs exactly as proposed, without asking the model again; once a waitpoint has no open
 * decision, its run resumes.
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
        private Memory $memory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Records the person's approval; the decision runs once it has the approvals it needs.
     *
     * @return bool whether this approval was the last one needed
     *
     * @throws DecisionClosedException
     */
    public function approve(Decision $decision, User $user, ?string $note = null): bool
    {
        $approved = $this->whileOpen($decision, 'approve', $user, function () use ($decision, $user, $note): bool {
            $this->vote($decision, $user, true, $note);
            if ($decision->countApprovals() < $decision->getRequiredApprovals()) {
                return false;
            }

            $decision->setNote($note);
            $this->workflow->apply($decision, 'approve');

            return true;
        });

        if ($approved) {
            $this->memory->record($decision, Verdict::Approved, $user);
            $this->bus->dispatch(new ExecuteDecision($decision->getId()->toRfc4122()));
        }

        return $approved;
    }

    /**
     * One rejection is enough.
     *
     * @throws DecisionClosedException
     */
    public function reject(Decision $decision, User $user, ?string $note = null): void
    {
        $this->whileOpen($decision, 'reject', $user, function () use ($decision, $user, $note): void {
            $this->vote($decision, $user, false, $note);
            $decision->setNote($note);
            $this->workflow->apply($decision, 'reject');
        });

        $this->memory->record($decision, Verdict::Rejected, $user);
        $this->resolveIfDone($decision->getWaitpoint());
    }

    /**
     * Fills in what the server asked for; the call is repeated with it.
     *
     * @param array<string, mixed> $content
     *
     * @throws DecisionClosedException
     */
    public function answer(Decision $decision, User $user, array $content): void
    {
        $question = $decision->getQuestion()?->message;
        $this->whileOpen($decision, 'answer', null, function () use ($decision, $user, $content): void {
            $decision->answer($content);
            $decision->setRespondent($user);
            $this->workflow->apply($decision, 'answer');
        });

        $this->memory->record($decision, Verdict::Answered, $user, $question);
        $this->bus->dispatch(new ExecuteDecision($decision->getId()->toRfc4122()));
    }

    /**
     * Refuses to answer; the call is not repeated and the agent is told.
     *
     * @throws DecisionClosedException
     */
    public function decline(Decision $decision, User $user, ?string $note = null): void
    {
        $this->whileOpen($decision, 'reject', null, function () use ($decision, $user, $note): void {
            $decision->setRespondent($user);
            $decision->setNote($note);
            $this->workflow->apply($decision, 'reject');
        });

        $this->memory->record($decision, Verdict::Declined, $user, $decision->getQuestion()?->message);
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

        $questions = new QuestionBox($decision->getAnswers());
        $result = null;
        $failure = null;
        try {
            $result = $this->clientFactory->callTool($connection, $decision->getRemoteToolName(), $decision->getArguments(), $questions);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        // The server cancelled the call to ask something new: wait for a person again.
        $question = $questions->takeAsked();
        if (null !== $question) {
            $decision->ask($question);
            $this->workflow->apply($decision, 'ask');
            $this->entityManager->flush();

            return;
        }

        if (null === $result) {
            $this->logger->error('Decision {decision} failed.', ['decision' => $decision->getId()->toRfc4122(), 'exception' => $failure]);
            $this->conclude($decision, 'fail', $failure->getMessage());

            return;
        }

        $this->conclude($decision, $result->isError ? 'fail' : 'execute', self::describe($result));
    }

    /**
     * Applies the change with the decision locked, if it is still open to this person.
     *
     * @template T
     *
     * @param \Closure(): T $change
     *
     * @return T
     *
     * @throws DecisionClosedException
     */
    private function whileOpen(Decision $decision, string $transition, ?User $voter, \Closure $change): mixed
    {
        // Returned, not thrown: an exception inside the transaction would close the entity manager.
        $outcome = $this->entityManager->wrapInTransaction(function () use ($decision, $transition, $voter, $change): mixed {
            $this->entityManager->lock($decision, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($decision);

            if (!$this->workflow->can($decision, $transition)) {
                return new DecisionClosedException('Someone already decided on it.');
            }
            if (null !== $voter && $decision->hasVoted($voter)) {
                return new DecisionClosedException('You already voted on it.');
            }

            return $change();
        });

        if ($outcome instanceof DecisionClosedException) {
            throw $outcome;
        }

        return $outcome;
    }

    private function vote(Decision $decision, User $user, bool $approve, ?string $note): void
    {
        $vote = new Vote($decision, $user, $approve, $note);
        $this->entityManager->persist($vote);
        $decision->addVote($vote);
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
