<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Connection;
use App\Entity\Reflex;
use Cron\CronExpression;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ReflexInput
{
    public const array MODELS = ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'];
    public const array SOURCES = ['generic', 'github', 'stripe', Reflex::SCHEDULE_SOURCE];

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Choice(choices: self::SOURCES)]
    public string $triggerSource = 'generic';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^(\*|[A-Za-z0-9_.:-]{1,100})$/', message: 'Use an event type such as "issue.opened", or "*" for any.')]
    public string $triggerEvent = Reflex::ANY_EVENT;

    #[Assert\Length(max: 100)]
    public ?string $cron = null;

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
        $scheduled = Reflex::SCHEDULE_SOURCE === $this->triggerSource;
        $reflex->update($this->name, $this->triggerSource, $scheduled ? Reflex::ANY_EVENT : $this->triggerEvent, $this->instructions, $this->model, $this->enabled, $this->connections);
        $reflex->setCron($scheduled ? trim((string) $this->cron) : null);
        $reflex->setLimits($this->dailyTokenBudget, $this->maxToolCalls, $this->timeLimitSeconds);
    }

    #[Assert\Callback]
    public function validateCron(ExecutionContextInterface $context): void
    {
        if (Reflex::SCHEDULE_SOURCE !== $this->triggerSource) {
            return;
        }

        if (null === $this->cron || !CronExpression::isValidExpression(trim($this->cron))) {
            $context->buildViolation('Use a cron expression such as "0 9 * * 1" (Mondays at 09:00 UTC).')
                ->atPath('cron')
                ->addViolation();
        }
    }
}
