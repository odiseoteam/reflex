<?php

declare(strict_types=1);

namespace App\Engine\Recording;

use App\Entity\Decision;
use App\Entity\Run;
use App\Repository\DecisionRepository;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * The model a run talks to: live, live while recording its answers, or replaying recorded
 * answers so the demo runs without an API key. "auto" replays only without a key.
 */
final readonly class ModelGateway
{
    public const string LIVE = 'live';
    public const string RECORD = 'record';
    public const string REPLAY = 'replay';

    private string $mode;
    private Recordings $recordings;

    public function __construct(
        #[Target('anthropic')]
        private PlatformInterface $platform,
        private DecisionRepository $decisions,
        #[Autowire('%env(REFLEX_MODELS)%')]
        string $mode,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        string $apiKey,
        #[Autowire('%kernel.project_dir%/demo/recordings')]
        string $directory,
    ) {
        $this->mode = match ($mode) {
            self::LIVE, self::RECORD, self::REPLAY => $mode,
            default => '' === trim($apiKey) ? self::REPLAY : self::LIVE,
        };
        $this->recordings = new Recordings($directory);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isReplaying(): bool
    {
        return self::REPLAY === $this->mode;
    }

    public function for(Run $run): PlatformInterface
    {
        if (self::LIVE === $this->mode) {
            return $this->platform;
        }

        return new RecordedPlatform(
            self::RECORD === $this->mode ? $this->platform : null,
            $this->platform,
            $this->recordings,
            $run->getReflexSnapshot()['name'],
            $this->moment($run),
        );
    }

    /**
     * Where the run is: what started it, which segment, and how people decided before it,
     * since a rejection and an approval lead to different answers.
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

        return \sprintf('%s/%d%s', $run->getEvent()?->getType() ?? 'manual', $run->getSegment(), [] === $outcomes ? '' : '/'.implode(',', $outcomes));
    }
}
