<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Connection;
use App\Entity\Reflex;
use App\Trigger\AbsenceTrigger;
use App\Trigger\PollTrigger;
use App\Trigger\SubscriptionTrigger;
use Cron\CronExpression;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ReflexInput
{
    public const array MODELS = ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5', Reflex::OPEN_MODEL];
    public const array WEBHOOK_SOURCES = ['generic', 'github', 'stripe'];
    public const array SOURCES = [...self::WEBHOOK_SOURCES, Reflex::SCHEDULE_SOURCE, Reflex::POLL_SOURCE, Reflex::ABSENCE_SOURCE, Reflex::SUBSCRIPTION_SOURCE];
    private const string EVENT_PATTERN = '/^[A-Za-z0-9_.:-]{1,100}$/';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Choice(choices: self::SOURCES)]
    public string $triggerSource = 'generic';

    public ?string $triggerEvent = Reflex::ANY_EVENT;

    #[Assert\Length(max: 100)]
    public ?string $cron = null;

    public ?Connection $pollConnection = null;

    #[Assert\Length(max: 255)]
    public ?string $pollTool = null;

    /** JSON object of arguments. */
    #[Assert\Length(max: 5000)]
    public ?string $pollArguments = null;

    #[Assert\Range(min: 1, max: 1440)]
    public int $pollIntervalMinutes = 5;

    #[Assert\Length(max: 100)]
    public ?string $pollItemKey = 'id';

    #[Assert\Choice(choices: self::WEBHOOK_SOURCES)]
    public string $absenceSource = 'generic';

    #[Assert\Length(max: 100)]
    public ?string $absenceWhen = null;

    #[Assert\Length(max: 100)]
    public ?string $absenceMissing = null;

    #[Assert\Range(min: 1, max: 10080)]
    public int $absenceWithinMinutes = 30;

    #[Assert\Length(max: 200)]
    public ?string $absenceCorrelation = null;

    public ?Connection $subscriptionConnection = null;

    /** One resource URI per line. */
    #[Assert\Length(max: 20000)]
    public ?string $subscriptionUris = null;

    public bool $subscriptionListChanged = false;

    #[Assert\NotBlank]
    #[Assert\Length(max: 10000)]
    public string $instructions = '';

    #[Assert\Choice(choices: self::MODELS)]
    public string $model = Reflex::DEFAULT_MODEL;

    public bool $enabled = true;

    #[Assert\Positive]
    public ?int $dailyTokenBudget = null;

    #[Assert\Range(min: 1, max: 50)]
    public int $maxToolCalls = Reflex::DEFAULT_MAX_TOOL_CALLS;

    #[Assert\Range(min: 10, max: 900)]
    public int $timeLimitSeconds = Reflex::DEFAULT_TIME_LIMIT;

    /** @var list<Connection> */
    public array $connections = [];

    public static function fromReflex(Reflex $reflex): self
    {
        $input = new self();
        $input->name = $reflex->getName();
        $input->triggerSource = $reflex->getTriggerSource();
        $input->triggerEvent = $reflex->getTriggerEvent();
        $input->cron = $reflex->getCron();
        $poll = $reflex->getPollTrigger();
        if (null !== $poll) {
            foreach ($reflex->getConnections() as $connection) {
                if ($connection->getId()->toRfc4122() === $poll->connectionId) {
                    $input->pollConnection = $connection;
                }
            }
            $input->pollTool = $poll->tool;
            $input->pollArguments = [] === $poll->arguments ? null : json_encode($poll->arguments, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $input->pollIntervalMinutes = $poll->intervalMinutes;
            $input->pollItemKey = $poll->itemKey;
        }
        $absence = $reflex->getAbsenceTrigger();
        if (null !== $absence) {
            $input->absenceSource = $absence->source;
            $input->absenceWhen = $absence->whenEvent;
            $input->absenceMissing = $absence->missingEvent;
            $input->absenceWithinMinutes = $absence->withinMinutes;
            $input->absenceCorrelation = $absence->correlationPath;
        }
        $subscription = $reflex->getSubscriptionTrigger();
        if (null !== $subscription) {
            foreach ($reflex->getConnections() as $connection) {
                if ($connection->getId()->toRfc4122() === $subscription->connectionId) {
                    $input->subscriptionConnection = $connection;
                }
            }
            $input->subscriptionUris = implode("\n", $subscription->resourceUris);
            $input->subscriptionListChanged = $subscription->listChanged;
        }
        $input->instructions = $reflex->getInstructions();
        $input->model = $reflex->getModel();
        $input->enabled = $reflex->isEnabled();
        $input->dailyTokenBudget = $reflex->getDailyTokenBudget();
        $input->maxToolCalls = $reflex->getMaxToolCalls();
        $input->timeLimitSeconds = $reflex->getTimeLimitSeconds();
        $input->connections = array_values($reflex->getConnections()->toArray());

        return $input;
    }

    public function applyTo(Reflex $reflex): void
    {
        $webhook = \in_array($this->triggerSource, self::WEBHOOK_SOURCES, true);
        $connections = $this->connections;
        if (Reflex::POLL_SOURCE === $this->triggerSource && null !== $this->pollConnection && !\in_array($this->pollConnection, $connections, true)) {
            $connections[] = $this->pollConnection;
        }
        if (Reflex::SUBSCRIPTION_SOURCE === $this->triggerSource && null !== $this->subscriptionConnection && !\in_array($this->subscriptionConnection, $connections, true)) {
            $connections[] = $this->subscriptionConnection;
        }

        $reflex->update($this->name, $this->triggerSource, $webhook ? (string) $this->triggerEvent : Reflex::ANY_EVENT, $this->instructions, $this->model, $this->enabled, $connections);
        $reflex->setCron(Reflex::SCHEDULE_SOURCE === $this->triggerSource ? trim((string) $this->cron) : null);
        $reflex->setTriggerConfig(match ($this->triggerSource) {
            Reflex::POLL_SOURCE => new PollTrigger(
                $this->pollConnection?->getId()->toRfc4122() ?? '',
                trim((string) $this->pollTool),
                $this->pollArgumentsArray() ?? [],
                $this->pollIntervalMinutes,
                '' === trim((string) $this->pollItemKey) ? null : trim((string) $this->pollItemKey),
            ),
            Reflex::SUBSCRIPTION_SOURCE => new SubscriptionTrigger($this->subscriptionConnection?->getId()->toRfc4122() ?? '', $this->subscriptionUriList(), $this->subscriptionListChanged),
            Reflex::ABSENCE_SOURCE => new AbsenceTrigger($this->absenceSource, trim((string) $this->absenceWhen), trim((string) $this->absenceMissing), $this->absenceWithinMinutes, trim((string) $this->absenceCorrelation)),
            default => null,
        });
        $reflex->setLimits($this->dailyTokenBudget, $this->maxToolCalls, $this->timeLimitSeconds);
    }

    #[Assert\Callback]
    public function validateTrigger(ExecutionContextInterface $context): void
    {
        $violation = static fn (string $path, string $message) => $context->buildViolation($message)->atPath($path)->addViolation();

        match ($this->triggerSource) {
            Reflex::SCHEDULE_SOURCE => null === $this->cron || !CronExpression::isValidExpression(trim($this->cron))
                ? $violation('cron', 'Use a cron expression such as "0 9 * * 1" (Mondays at 09:00 UTC).')
                : null,
            Reflex::POLL_SOURCE => $this->validatePoll($violation),
            Reflex::ABSENCE_SOURCE => $this->validateAbsence($violation),
            Reflex::SUBSCRIPTION_SOURCE => $this->validateSubscription($violation),
            default => 1 === preg_match('/^(\*|[A-Za-z0-9_.:-]{1,100})$/', (string) $this->triggerEvent)
                ? null
                : $violation('triggerEvent', 'Use an event type such as "issue.opened", or "*" for any.'),
        };
    }

    /**
     * @param \Closure(string, string): void $violation
     */
    private function validatePoll(\Closure $violation): void
    {
        if (null === $this->pollConnection) {
            $violation('pollConnection', 'Pick the connection to poll.');
        }
        if (1 !== preg_match('/^[A-Za-z0-9_.-]{1,255}$/', trim((string) $this->pollTool))) {
            $violation('pollTool', 'Use the tool name as the server lists it, e.g. "list_issues".');
        }
        if (null === $this->pollArgumentsArray()) {
            $violation('pollArguments', 'Use a JSON object, e.g. {"state": "open"}.');
        }
    }

    /**
     * @param \Closure(string, string): void $violation
     */
    private function validateAbsence(\Closure $violation): void
    {
        foreach (['absenceWhen' => $this->absenceWhen, 'absenceMissing' => $this->absenceMissing] as $path => $event) {
            if (1 !== preg_match(self::EVENT_PATTERN, trim((string) $event))) {
                $violation($path, 'Use an event type such as "email_captured".');
            }
        }
        if (null !== $this->absenceWhen && trim($this->absenceWhen) === trim((string) $this->absenceMissing)) {
            $violation('absenceMissing', 'It must differ from the first event.');
        }
        if (1 !== preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', trim((string) $this->absenceCorrelation))) {
            $violation('absenceCorrelation', 'Use a dot path into the payload, e.g. "customer.email".');
        }
    }

    /**
     * @param \Closure(string, string): void $violation
     */
    private function validateSubscription(\Closure $violation): void
    {
        if (null === $this->subscriptionConnection) {
            $violation('subscriptionConnection', 'Pick the connection whose server notifies the changes.');
        }

        $uris = $this->subscriptionUriList();
        if ([] === $uris && !$this->subscriptionListChanged) {
            $violation('subscriptionUris', 'Name at least one resource, or react to list changes.');
        }
        if (\count($uris) > SubscriptionTrigger::MAX_URIS) {
            $violation('subscriptionUris', \sprintf('Up to %d resources.', SubscriptionTrigger::MAX_URIS));
        }
        foreach ($uris as $uri) {
            if (1 !== preg_match('/^[a-z][a-z0-9+.-]*:\S+$/i', $uri) || \strlen($uri) > 2048) {
                $violation('subscriptionUris', \sprintf('"%s" is not a resource URI such as "tracker://issues/42".', mb_substr($uri, 0, 80)));
                break;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function subscriptionUriList(): array
    {
        $lines = preg_split('/\R/', (string) $this->subscriptionUris) ?: [];

        return array_values(array_unique(array_filter(array_map(trim(...), $lines), static fn (string $uri): bool => '' !== $uri)));
    }

    /**
     * @return array<string, mixed>|null null when it isn't a JSON object
     */
    private function pollArgumentsArray(): ?array
    {
        $json = trim((string) $this->pollArguments);
        if ('' === $json) {
            return [];
        }

        try {
            $arguments = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return PollTrigger::object($arguments);
    }
}
