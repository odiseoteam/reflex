<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\PolarCoffee;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'reflex:demo:setup', description: 'Sets up Polar Coffee, the demo company: people, connections and reflexes.')]
final readonly class DemoSetupCommand
{
    public function __construct(
        private PolarCoffee $polarCoffee,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Password of the demo people.')] string $password = 'polarcoffee',
        #[Option('Put the shop and helpdesk back to their starting data.')] bool $resetData = false,
        #[Option('Run it in production too.')] bool $force = false,
    ): int {
        if ('prod' === $this->environment && !$force) {
            $io->error('This creates demo people with a known password. Use --force to run it in production.');

            return Command::FAILURE;
        }

        $result = $this->polarCoffee->install($password, $resetData);

        $io->title('Polar Coffee is ready');
        $io->definitionList(
            ['Sign in as' => implode(', ', $result['people'])],
            ['Password' => '--password, "polarcoffee" by default'],
            ...array_map(static fn (string $name, string $status): array => [$name => $status], array_keys($result['connections']), $result['connections']),
        );
        $io->listing($result['reflexes']);
        if (\in_array('failed', $result['connections'], true)) {
            $io->warning('A demo server did not answer. Is the "demo" service up?');
        }
        $io->text('Then try: bin/console reflex:simulate --list');

        return Command::SUCCESS;
    }
}
