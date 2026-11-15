<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Connection;
use App\Entity\Reflex;
use Symfony\Component\Validator\Constraints as Assert;

final class ReflexInput
{
    public const array MODELS = ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'];
    public const array SOURCES = ['generic'];

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Choice(choices: self::SOURCES)]
    public string $triggerSource = 'generic';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^(\*|[A-Za-z0-9_.:-]{1,100})$/', message: 'Use an event type such as "issue.opened", or "*" for any.')]
    public string $triggerEvent = Reflex::ANY_EVENT;

    #[Assert\NotBlank]
    #[Assert\Length(max: 10000)]
    public string $instructions = '';

    #[Assert\Choice(choices: self::MODELS)]
    public string $model = Reflex::DEFAULT_MODEL;

    public bool $enabled = true;

    /** @var list<Connection> */
    public array $connections = [];

    public static function fromReflex(Reflex $reflex): self
    {
        $input = new self();
        $input->name = $reflex->getName();
        $input->triggerSource = $reflex->getTriggerSource();
        $input->triggerEvent = $reflex->getTriggerEvent();
        $input->instructions = $reflex->getInstructions();
        $input->model = $reflex->getModel();
        $input->enabled = $reflex->isEnabled();
        $input->connections = array_values($reflex->getConnections()->toArray());

        return $input;
    }

    public function applyTo(Reflex $reflex): void
    {
        $reflex->update($this->name, $this->triggerSource, $this->triggerEvent, $this->instructions, $this->model, $this->enabled, $this->connections);
    }
}
