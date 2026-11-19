<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Engine\DecisionClosedException;
use App\Engine\DecisionDesk;
use App\Entity\Decision;
use App\Entity\DecisionKind;
use App\Entity\User;
use App\Entity\Vote;
use App\Form\QuestionFormFactory;
use App\Memory\Memory;
use App\Memory\Recollection;
use App\Repository\DecisionRepository;
use App\Security\DecisionVoter;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Capability\Attribute\Schema;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The decision inbox over MCP, acting as the person who connected the app.
 */
final readonly class InboxTools
{
    public function __construct(
        private DecisionRepository $decisions,
        private DecisionDesk $desk,
        private QuestionFormFactory $questionForms,
        private Memory $memory,
        private Security $security,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_decisions', title: 'List decisions', description: 'Proposals and server questions waiting for a person, and the latest ones decided.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function list(
        #[Schema(description: 'Also list recently decided ones.')]
        bool $includeDecided = false,
    ): array {
        $result = ['pending' => array_map($this->summary(...), $this->decisions->findPending())];
        if ($includeDecided) {
            $result['decided'] = array_map($this->summary(...), $this->decisions->findRecentlyDecided(10));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_decision', title: 'Get a decision', description: 'Everything about one decision: the exact call that runs if approved, votes, the server question if any, and similar past decisions.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function get(
        #[Schema(description: 'Decision id (UUID).')]
        string $id,
    ): array {
        $decision = $this->find($id);
        $details = $this->summary($decision) + [
            'arguments' => $decision->getArguments(),
            'connection' => $decision->getConnection()?->getName(),
            'votes' => array_map(static fn (Vote $vote): array => [
                'by' => $vote->getUser()->getName(),
                'approve' => $vote->isApprove(),
                'note' => $vote->getNote(),
            ], $decision->getVotes()->toArray()),
            'note' => $decision->getNote(),
            'result' => $decision->getResult(),
            'you_can_decide' => $this->security->isGranted(DecisionVoter::DECIDE, $decision),
            'you_can_answer' => $this->security->isGranted(DecisionVoter::ANSWER, $decision),
        ];

        if (null !== $question = $decision->getQuestion()) {
            $details['question'] = ['message' => $question->message, 'schema' => $question->schema];
        }
        if ($decision->isPending()) {
            $details['similar_past_decisions'] = array_map(static fn (Recollection $recollection): array => [
                'verdict' => $recollection->precedent->getVerdict()->value,
                'by' => $recollection->precedent->getDecidedBy(),
                'tool' => $recollection->precedent->getToolName(),
                'reason' => $recollection->precedent->getReason(),
                'note' => $recollection->precedent->getNote(),
                'decided_at' => $recollection->precedent->getDecidedAt()->format(\DATE_ATOM),
            ], $this->memory->recallFor($decision));
        }

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'approve_decision', title: 'Approve a proposal', description: 'Approves a proposal as you. Once it has the approvals it needs, the exact call shown by get_decision runs. Only use it when the user asked to approve this decision.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true))]
    public function approve(
        #[Schema(description: 'Decision id (UUID).')]
        string $id,
        #[Schema(description: 'Optional note for the agent and the other approvers.', maxLength: 2000)]
        ?string $note = null,
    ): array {
        $decision = $this->find($id);
        $this->deny(DecisionVoter::DECIDE, $decision, 'You can\'t approve it: it isn\'t a pending proposal, you already voted, or you are a viewer.');

        $approved = $this->act(fn (): bool => $this->desk->approve($decision, $this->user(), self::note($note)));

        return [
            'status' => $decision->getStatus(),
            'approvals' => $decision->countApprovals(),
            'required_approvals' => $decision->getRequiredApprovals(),
            'message' => $approved ? 'Approved. It runs exactly as proposed.' : 'Your approval is recorded; it needs more before it runs.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'reject_decision', title: 'Reject a proposal', description: 'Rejects a proposal as you; the agent is told, with your note, and nothing runs.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false))]
    public function reject(
        #[Schema(description: 'Decision id (UUID).')]
        string $id,
        #[Schema(description: 'Why, for the agent. It is remembered for similar cases.', maxLength: 2000)]
        ?string $note = null,
    ): array {
        $decision = $this->find($id);
        $this->deny(DecisionVoter::DECIDE, $decision, 'You can\'t reject it: it isn\'t a pending proposal, you already voted, or you are a viewer.');
        $this->act(fn () => $this->desk->reject($decision, $this->user(), self::note($note)));

        return ['status' => $decision->getStatus(), 'message' => 'Rejected. The agent is told why.'];
    }

    /**
     * @param array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'answer_question', title: 'Answer a server question', description: 'Answers the question an MCP server asked in the middle of a call; the call is repeated with the answer. The answer must follow the question schema from get_decision.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true))]
    public function answer(
        #[Schema(description: 'Decision id (UUID).')]
        string $id,
        #[Schema(description: 'Field values, keyed as in the question schema.', type: 'object')]
        array $answer,
    ): array {
        $decision = $this->find($id);
        $this->deny(DecisionVoter::ANSWER, $decision, 'You can\'t answer it: no question is open, or you are a viewer.');
        $question = $decision->getQuestion() ?? throw new ToolCallException('No question is open on this decision.');

        $form = $this->questionForms->create($question, ['csrf_protection' => false]);
        $form->submit(self::submitted($answer));
        if (!$form->isValid()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = ($error->getOrigin()?->getName() ?? 'answer').': '.$error->getMessage();
            }

            throw new ToolCallException('The answer does not fit the question: '.implode('; ', $errors));
        }

        $this->act(fn () => $this->desk->answer($decision, $this->user(), $this->questionForms->content($question, $form)));

        return ['status' => $decision->getStatus(), 'message' => 'Answered. The call is repeated with your answer.'];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'decline_question', title: 'Decline a server question', description: 'Refuses to answer a server question; the call is not repeated and the agent is told.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false))]
    public function decline(
        #[Schema(description: 'Decision id (UUID).')]
        string $id,
        #[Schema(description: 'Optional note for the agent.', maxLength: 2000)]
        ?string $note = null,
    ): array {
        $decision = $this->find($id);
        $this->deny(DecisionVoter::ANSWER, $decision, 'You can\'t decline it: no question is open, or you are a viewer.');
        $this->act(fn () => $this->desk->decline($decision, $this->user(), self::note($note)));

        return ['status' => $decision->getStatus(), 'message' => 'Declined. The agent is told.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Decision $decision): array
    {
        $question = $decision->getQuestion();

        return [
            'id' => $decision->getId()->toRfc4122(),
            'kind' => $decision->getKind()->value,
            'status' => $decision->getStatus(),
            'tool' => $decision->getToolName(),
            'reason' => DecisionKind::Question === $decision->getKind() && null !== $question ? $question->message : $decision->getReason(),
            'reflex' => $decision->getRun()->getReflexSnapshot()['name'],
            'run_id' => $decision->getRun()->getId()->toRfc4122(),
            'approvals' => $decision->countApprovals(),
            'required_approvals' => $decision->getRequiredApprovals(),
            'created_at' => $decision->getCreatedAt()->format(\DATE_ATOM),
            'url' => $this->urls->generate('app_decision_show', ['id' => $decision->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    private function find(string $id): Decision
    {
        $decision = Uuid::isValid($id) ? $this->decisions->find(Uuid::fromString($id)) : null;

        return $decision ?? throw new ToolCallException(\sprintf('No decision with id "%s". Use list_decisions to find one.', $id));
    }

    private function deny(string $attribute, Decision $decision, string $message): void
    {
        if (!$this->security->isGranted($attribute, $decision)) {
            throw new ToolCallException($message);
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $action
     *
     * @return T
     */
    private function act(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (DecisionClosedException $e) {
            throw new ToolCallException($e->getMessage());
        }
    }

    private function user(): User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : throw new ToolCallException('Sign in to Reflex first.');
    }

    private static function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return '' === $note ? null : mb_substr($note, 0, 2000);
    }

    /**
     * JSON values as a browser would submit them, so the same form validates both.
     *
     * @param array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    private static function submitted(array $answer): array
    {
        $submitted = [];
        foreach ($answer as $name => $value) {
            $submitted[$name] = match (true) {
                true === $value => '1',
                false === $value, null === $value => null,
                \is_array($value) => array_map(static fn (mixed $item): string => \is_scalar($item) ? (string) $item : '', $value),
                \is_scalar($value) => (string) $value,
                default => null,
            };
        }

        return $submitted;
    }
}
