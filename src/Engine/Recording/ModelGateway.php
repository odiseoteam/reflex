<?php

declare(strict_types=1);

namespace App\Engine\Recording;

use App\Entity\Decision;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Repository\DecisionRepository;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * The model a run talks to: Anthropic, or Hugging Face for open models ("org/model"); live,
 * live while recording its answers, replaying recorded answers so the demo runs without an
 * API key, or filling in what is not recorded yet. "auto" replays only when the model's
 * provider has no key.
 */
final readonly class ModelGateway
{
    public const string LIVE = 'live';
    public const string RECORD = 'record';
    public const string REPLAY = 'replay';
    /** Replays what is recorded and records, live, only what is missing. */
    public const string FILL = 'fill';
    public const string NO_RUN_BY_HAND = 'Running it by hand needs an API key for its model: its recorded answers only cover the events it reacts to. Make one happen with bin/console reflex:simulate.';
    /** Moment of the first segment of a run without an event. */
    private const string MANUAL_MOMENT = 'manual/1';

    private Recordings $recordings;

    public function __construct(
        #[Target('anthropic')]
        private PlatformInterface $anthropic,
        #[Target('huggingface')]
        private PlatformInterface $huggingFace,
        private DecisionRepository $decisions,
        #[Autowire('%env(REFLEX_MODELS)%')]
        private string $mode,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        private string $anthropicKey,
        #[Autowire('%env(HF_TOKEN)%')]
        private string $huggingFaceToken,
        #[Autowire('%kernel.project_dir%/demo/recordings')]
        string $directory,
    ) {
        $this->recordings = new Recordings($directory);
    }

    public function mode(string $model): string
    {
        return match ($this->mode) {
            self::LIVE, self::RECORD, self::REPLAY, self::FILL => $this->mode,
            default => '' === trim(self::isOpen($model) ? $this->huggingFaceToken : $this->anthropicKey) ? self::REPLAY : self::LIVE,
        };
    }

    /**
     * Whether a run without an event can get answers: from the live model, or from a recording
     * of one. Demo recordings mostly answer events, so most can't run by hand without a key.
     */
    public function canRunByHand(Reflex $reflex): bool
    {
        return self::REPLAY !== $this->mode($reflex->getModel())
            || null !== $this->recordings->find($reflex->getName(), self::MANUAL_MOMENT.'#1');
    }

    public function isReplaying(Run $run): bool
    {
        return self::REPLAY === $this->mode($run->getReflexSnapshot()['model']);
    }

    public function for(Run $run): PlatformInterface
    {
        $model = $run->getReflexSnapshot()['model'];
        $platform = self::isOpen($model) ? $this->huggingFace : $this->anthropic;
        $mode = $this->mode($model);
        if (self::LIVE === $mode) {
            return $platform;
        }

        return new RecordedPlatform(
            self::REPLAY === $mode ? null : $platform,
            $platform,
            $this->recordings,
            $run->getReflexSnapshot()['name'],
            $this->moment($run),
            self::FILL === $mode,
        );
    }

    public static function isOpen(string $model): bool
    {
        return str_contains($model, '/');
    }

    /**
     * Where the run is: what started it and whom it is about, which segment, and how people
     * decided before it, since another customer or another decision leads to other answers.
     */
    private function moment(Run $run): string
    {
        $outcomes = [];
        foreach ($this->decisions->findByRun($run) as $decision) {
            if ($decision->getSegment() === $run->getSegment() - 1) {
                $outcomes[] = $decision->getToolName().'='.match ($decision->getStatus()) {
                    Decision::EXECUTED => 'executed',
                    Decision::FAILED => 'failed',
                    default => 'rejected',
                };
            }
        }
        sort($outcomes);

        $event = $run->getEvent();
        $customer = $event?->getPayload()['customer'] ?? null;
        $customer = \is_array($customer) ? $customer['email'] ?? null : null;

        return \sprintf(
            '%s%s/%d%s',
            $event?->getType() ?? 'manual',
            \is_string($customer) ? '['.$customer.']' : '',
            $run->getSegment(),
            [] === $outcomes ? '' : '/'.implode(',', $outcomes),
        );
    }
}
