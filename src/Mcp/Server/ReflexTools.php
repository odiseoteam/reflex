<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Engine\Recording\ModelGateway;
use App\Entity\Connection;
use App\Entity\Decision;
use App\Entity\Precedent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\RunStep;
use App\Entity\User;
use App\Form\ReflexInput;
use App\Message\RunReflex;
use App\Repository\ConnectionRepository;
use App\Repository\DecisionRepository;
use App\Repository\PrecedentRepository;
use App\Repository\ReflexRepository;
use App\Repository\RunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Capability\Attribute\Schema;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reflexes and their runs over MCP.
 */
final readonly class ReflexTools
{
    private const int OUTPUT_EXCERPT = 500;

    public function __construct(
        private ReflexRepository $reflexes,
        private RunRepository $runs,
        private DecisionRepository $decisions,
        private PrecedentRepository $precedents,
        private ConnectionRepository $connections,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private MessageBusInterface $bus,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private ModelGateway $models,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'list_reflexes', title: 'List reflexes', description: 'The reflexes of this Reflex instance: what triggers each one and whether it is enabled.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function list(): array
    {
        return array_map($this->summary(...), $this->reflexes->findAllOrdered());
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_reflex', title: 'Get a reflex', description: 'A reflex with its instructions, tool policies, latest runs and what people decided on its proposals.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function get(
        #[Schema(description: 'Reflex id (UUID).')]
        string $id,
    ): array {
        $reflex = $this->find($id);

        return $this->summary($reflex) + [
            'instructions' => $reflex->getInstructions(),
            'tool_policies' => $reflex->getToolPolicies(),
            'required_approvals' => $reflex->getRequiredApprovals(),
            'daily_token_budget' => $reflex->getDailyTokenBudget(),
            'latest_runs' => array_map($this->runSummary(...), $this->runs->findLatest($reflex, 5)),
            'memory' => array_map(static fn (Precedent $precedent): array => [
                'verdict' => $precedent->getVerdict()->value,
                'tool' => $precedent->getToolName(),
                'by' => $precedent->getDecidedBy(),
                'note' => $precedent->getNote(),
                'decided_at' => $precedent->getDecidedAt()->format(\DATE_ATOM),
            ], $this->precedents->findLatest($reflex, 10)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'list_runs', title: 'List runs', description: 'The latest runs, of all reflexes or of one.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function runs(
        #[Schema(description: 'Only the runs of this reflex (UUID).')]
        ?string $reflexId = null,
        #[Schema(minimum: 1, maximum: 50)]
        int $limit = 10,
    ): array {
        $reflex = null === $reflexId ? null : $this->find($reflexId);

        return array_map($this->runSummary(...), $this->runs->findLatest($reflex, max(1, min(50, $limit))));
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_run', title: 'Get a run', description: 'A run with its event, the tool calls it made, its decisions, the precedents it recalled and its summary.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function run(
        #[Schema(description: 'Run id (UUID).')]
        string $id,
    ): array {
        $run = Uuid::isValid($id) ? $this->runs->find(Uuid::fromString($id)) : null;
        if (null === $run) {
            throw new ToolCallException(\sprintf('No run with id "%s". Use list_runs to find one.', $id));
        }

        $event = $run->getEvent();

        return $this->runSummary($run) + [
            'event' => null === $event ? null : ['source' => $event->getSource(), 'type' => $event->getType(), 'payload' => $event->getPayload()],
            'steps' => array_map(static fn (RunStep $step): array => [
                'kind' => $step->getKind()->value,
                'tool' => $step->getToolName(),
                'arguments' => $step->getArguments(),
            ], $run->getSteps()->toArray()),
            'decisions' => array_map(static fn (Decision $decision): array => [
                'id' => $decision->getId()->toRfc4122(),
                'kind' => $decision->getKind()->value,
                'status' => $decision->getStatus(),
                'tool' => $decision->getToolName(),
            ], $this->decisions->findByRun($run)),
            'recalled_precedents' => array_map(static fn (Precedent $precedent): string => \sprintf('%s %s by %s', $precedent->getVerdict()->value, $precedent->getToolName(), $precedent->getDecidedBy()), $this->precedents->findInOrder($run->getRecalled())),
            'output' => $run->getOutput(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'run_reflex', title: 'Run a reflex now', description: 'Starts a run of a reflex without an event. Its tool calls follow its policies, so anything gated still waits for approval.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true))]
    public function start(
        #[Schema(description: 'Reflex id (UUID).')]
        string $id,
    ): array {
        $this->requireAdmin();
        $reflex = $this->find($id);
        if (!$this->models->canRunByHand($reflex)) {
            throw new ToolCallException(ModelGateway::NO_RUN_BY_HAND);
        }

        $run = new Run($reflex);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->bus->dispatch(new RunReflex($run->getId()->toRfc4122()));

        return $this->runSummary($run) + ['message' => 'Queued. Check it with get_run.'];
    }

    /**
     * @param list<string> $connections
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_reflex', title: 'Create a reflex', description: 'Creates a reflex triggered by a webhook event or a cron schedule. Polling and "X without Y" triggers are set up in the Reflex UI. Tools without a policy need approval, so a new reflex proposes before it acts.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false))]
    public function create(
        #[Schema(maxLength: 100)]
        string $name,
        #[Schema(description: 'What the agent should do when triggered.', maxLength: 10000)]
        string $instructions,
        #[Schema(description: 'Webhook source, or "schedule" for a cron.', enum: [...ReflexInput::WEBHOOK_SOURCES, Reflex::SCHEDULE_SOURCE])]
        string $trigger = 'generic',
        #[Schema(description: 'Event type that triggers it, e.g. "issue.opened", or "*" for any. Ignored for "schedule".')]
        string $event = Reflex::ANY_EVENT,
        #[Schema(description: 'Cron expression in UTC, for "schedule", e.g. "0 9 * * 1".')]
        ?string $cron = null,
        #[Schema(description: 'Names of the connections whose tools it may use.', items: ['type' => 'string'])]
        array $connections = [],
        #[Schema(enum: ReflexInput::MODELS)]
        string $model = Reflex::DEFAULT_MODEL,
        bool $enabled = true,
    ): array {
        $this->requireAdmin();
        if (!\in_array($trigger, [...ReflexInput::WEBHOOK_SOURCES, Reflex::SCHEDULE_SOURCE], true)) {
            throw new ToolCallException('Use a webhook source or "schedule"; set up polling and absence triggers in the Reflex UI.');
        }

        $input = new ReflexInput();
        $input->name = trim($name);
        $input->instructions = trim($instructions);
        $input->triggerSource = $trigger;
        $input->triggerEvent = $event;
        $input->cron = $cron;
        $input->model = $model;
        $input->enabled = $enabled;
        $input->connections = $this->connectionsNamed($connections);

        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath().': '.$violation->getMessage();
            }

            throw new ToolCallException('The reflex is not valid: '.implode('; ', $messages));
        }

        $reflex = new Reflex($input->name, $input->triggerSource, $input->triggerEvent ?? Reflex::ANY_EVENT, $input->instructions, $input->model);
        $input->applyTo($reflex);
        $this->entityManager->persist($reflex);
        $this->entityManager->flush();

        return $this->summary($reflex) + ['message' => 'Created. Review its tool policies in the Reflex UI.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Reflex $reflex): array
    {
        return [
            'id' => $reflex->getId()->toRfc4122(),
            'name' => $reflex->getName(),
            'enabled' => $reflex->isEnabled(),
            'trigger' => self::trigger($reflex),
            'model' => $reflex->getModel(),
            'connections' => array_values(array_map(static fn (Connection $connection): string => $connection->getName(), $reflex->getConnections()->toArray())),
            'url' => $this->urls->generate('app_reflex_show', ['id' => $reflex->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runSummary(Run $run): array
    {
        $output = $run->getOutput();

        return [
            'id' => $run->getId()->toRfc4122(),
            'reflex' => $run->getReflexSnapshot()['name'],
            'status' => $run->getStatus()->value,
            'event' => $run->getEvent()?->getType(),
            'created_at' => $run->getCreatedAt()->format(\DATE_ATOM),
            'tokens' => $run->getTokensUsed(),
            'output' => null === $output ? null : mb_substr($output, 0, self::OUTPUT_EXCERPT),
            'error' => $run->getError(),
            'url' => $this->urls->generate('app_run_show', ['id' => $run->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    private static function trigger(Reflex $reflex): string
    {
        $poll = $reflex->getPollTrigger();
        $absence = $reflex->getAbsenceTrigger();
        $subscription = $reflex->getSubscriptionTrigger();

        return match (true) {
            null !== $reflex->getCron() => \sprintf('cron "%s" (UTC)', $reflex->getCron()),
            null !== $poll => \sprintf('polls %s every %d min', $poll->tool, $poll->intervalMinutes),
            null !== $absence => \sprintf('%s without %s within %d min', $absence->whenEvent, $absence->missingEvent, $absence->withinMinutes),
            null !== $subscription => \sprintf('subscribed to %s%s', implode(', ', $subscription->resourceUris) ?: 'no resources', $subscription->listChanged ? ' and resource list changes' : ''),
            default => \sprintf('%s webhook, event %s', $reflex->getTriggerSource(), $reflex->getTriggerEvent()),
        };
    }

    private function find(string $id): Reflex
    {
        $reflex = Uuid::isValid($id) ? $this->reflexes->find(Uuid::fromString($id)) : null;

        return $reflex ?? throw new ToolCallException(\sprintf('No reflex with id "%s". Use list_reflexes to find one.', $id));
    }

    /**
     * @param list<string> $names
     *
     * @return list<Connection>
     */
    private function connectionsNamed(array $names): array
    {
        $byName = [];
        foreach ($this->connections->findAllOrdered() as $connection) {
            $byName[mb_strtolower($connection->getName())] = $connection;
        }

        $found = [];
        foreach ($names as $name) {
            $found[] = $byName[mb_strtolower(trim($name))] ?? throw new ToolCallException(\sprintf('No connection named "%s". Known: %s.', $name, implode(', ', array_map(static fn (Connection $connection): string => $connection->getName(), $byName)) ?: 'none'));
        }

        return $found;
    }

    private function requireAdmin(): void
    {
        if (!$this->security->isGranted(User::ROLE_ADMIN)) {
            throw new ToolCallException('Only admins can do that; you are a viewer.');
        }
    }
}
