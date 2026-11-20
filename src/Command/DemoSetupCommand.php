<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\PolarCoffee;
use App\Entity\Connection;
use App\Entity\ConnectionStatus;
use App\Entity\Reflex;
use App\Entity\User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(name: 'reflex:demo:setup', description: 'Sets up Polar Coffee Store, the demo company: people, connections and reflexes.')]
final readonly class DemoSetupCommand
{
    private const string DEFAULT_PASSWORD = 'polarcoffee';

    public function __construct(
        private PolarCoffee $polarCoffee,
        private UrlGeneratorInterface $urls,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Password of the demo people.')] string $password = self::DEFAULT_PASSWORD,
        #[Option('Put the shop and helpdesk back to their starting data.')] bool $resetData = false,
        #[Option('Run it in production too.')] bool $force = false,
    ): int {
        if ('prod' === $this->environment && !$force) {
            $io->error('This creates demo people with a known password. Use --force to run it in production.');

            return Command::FAILURE;
        }

        $result = $this->polarCoffee->install($password, $resetData);

        $io->title('Polar Coffee Store, the demo company, is ready');

        $io->section('People');
        $io->table(['Email', 'Name', 'Can'], array_map(static fn (User $user): array => [
            $user->getEmail(),
            $user->getName(),
            $user->isAdmin() ? 'approve decisions' : 'see the inbox, not approve',
        ], $result['people']));
        $io->text(\sprintf('Password: %s', self::DEFAULT_PASSWORD === $password ? $password : 'the one given with --password'));

        $io->section('MCP connections');
        $io->table(['Name', 'Server', 'Status'], array_map(static fn (Connection $connection): array => [
            $connection->getName(),
            $connection->getUrl(),
            ConnectionStatus::Connected === $connection->getStatus()
                ? \sprintf('connected, %d tools', \count($connection->getTools()))
                : $connection->getStatus()->value,
        ], $result['connections']));
        if (array_any($result['connections'], static fn (Connection $connection): bool => ConnectionStatus::Connected !== $connection->getStatus())) {
            $io->warning('A demo server did not answer. Is the "demo" service up?');
        }

        $io->section('Reflexes');
        $io->table(['Name', 'Runs on'], array_map(static fn (Reflex $reflex): array => [
            $reflex->getName(),
            self::trigger($reflex),
        ], $result['reflexes']));

        $io->section('Next');
        $io->listing([
            \sprintf('Sign in at %s', $this->urls->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL)),
            'Make something happen: docker compose exec php bin/console reflex:simulate --list',
        ]);

        return Command::SUCCESS;
    }

    private static function trigger(Reflex $reflex): string
    {
        if ($reflex->isScheduled()) {
            return \sprintf('schedule "%s" (UTC)', $reflex->getCron());
        }
        if (null !== $poll = $reflex->getPollTrigger()) {
            return \sprintf('polling %s every %d min', $poll->tool, $poll->intervalMinutes);
        }
        if (null !== $absence = $reflex->getAbsenceTrigger()) {
            return \sprintf('%s without %s within %d min', $absence->whenEvent, $absence->missingEvent, $absence->withinMinutes);
        }
        if (null !== $subscription = $reflex->getSubscriptionTrigger()) {
            return \sprintf('MCP subscription to %s', implode(', ', $subscription->resourceUris));
        }

        return \sprintf('webhook %s', $reflex->getTriggerEvent());
    }
}
