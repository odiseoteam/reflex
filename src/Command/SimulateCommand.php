<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\Scenarios;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'reflex:simulate', description: 'Makes something happen at Polar Coffee Store, the demo company.')]
final readonly class SimulateCommand
{
    public function __construct(
        private Scenarios $scenarios,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('What happens; see --list.')] ?string $scenario = null,
        #[Option('Who it happens to, for payment-failed: ana, bruno or carla.')] string $customer = 'ana',
        #[Option('List the scenarios.')] bool $list = false,
    ): int {
        if ($list || null === $scenario) {
            $io->definitionList(...array_map(static fn (string $name, string $description): array => [$name => $description], array_keys(Scenarios::ALL), Scenarios::ALL));

            return Command::SUCCESS;
        }

        try {
            $io->success($this->scenarios->play($scenario, $customer));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        return Command::SUCCESS;
    }
}
