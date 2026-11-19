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
 * The model a run talks to: Anthropic, or Hugging Face for open models ("org/model"); live,
 * live while recording its answers, or replaying recorded answers so the demo runs without
 * an API key. "auto" replays only when the model's provider has no key.
 */
final readonly class ModelGateway
{
    public const string LIVE = 'live';
    public const string RECORD = 'record';
    public const string REPLAY = 'replay';

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
            self::LIVE, self::RECORD, self::REPLAY => $this->mode,
            default => '' === trim(self::isOpen($model) ? $this->huggingFaceToken : $this->anthropicKey) ? self::REPLAY : self::LIVE,
        };
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
            self::RECORD === $mode ? $platform : null,
            $platform,
            $this->recordings,
            $run->getReflexSnapshot()['name'],
            $this->moment($run),
        );
    }

    public static function isOpen(string $model): bool
    {
        return str_contains($model, '/');
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
