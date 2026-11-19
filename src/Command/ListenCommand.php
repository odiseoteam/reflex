<?php

declare(strict_types=1);

namespace App\Command;

use App\Trigger\SubscriptionListener;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Keeps a listener process per connection that subscription reflexes use, restarting it
 * when what to listen for changes or when it exits. Each MCP client blocks while it listens,
 * so connections can't share a process.
 */
#[AsCommand(name: 'reflex:listen', description: 'Listens to MCP servers for the resource changes subscription reflexes react to.')]
final class ListenCommand
{
    private const int CHECK_EVERY = 5;

    /** @var array<string, array{Process, string}> process and filter, by connection id */
    private array $children = [];

    public function __construct(
        private readonly SubscriptionListener $listener,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        #[Option('Seconds to run before exiting; 0 runs until stopped.')] int $timeLimit = 0,
    ): int {
        $until = 0 === $timeLimit ? \PHP_INT_MAX : time() + $timeLimit;

        try {
            while (time() < $until) {
                $this->entityManager->clear();
                $this->reconcile($this->listener->plan());
                sleep(self::CHECK_EVERY);
            }
        } finally {
            foreach ($this->children as [$process]) {
                $process->stop(3);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, \Mcp\Schema\SubscriptionFilter> $plan
     */
    private function reconcile(array $plan): void
    {
        foreach ($this->children as $id => [$process, $fingerprint]) {
            $wanted = isset($plan[$id]) ? json_encode($plan[$id], \JSON_THROW_ON_ERROR) : null;
            if ($process->isRunning() && $wanted === $fingerprint) {
                continue;
            }

            $process->stop(3);
            unset($this->children[$id]);
        }

        foreach ($plan as $id => $filter) {
            if (isset($this->children[$id])) {
                continue;
            }

            $process = new Process([\PHP_BINARY, $this->projectDir.'/bin/console', 'reflex:listen:connection', $id, '--no-interaction'], $this->projectDir, timeout: null);
            $process->start(static function (string $type, string $output): void {
                fwrite(Process::ERR === $type ? \STDERR : \STDOUT, $output);
            });
            $this->children[$id] = [$process, json_encode($filter, \JSON_THROW_ON_ERROR)];
            $this->logger->info('Started a listener for connection {id}.', ['id' => $id]);
        }
    }
}
