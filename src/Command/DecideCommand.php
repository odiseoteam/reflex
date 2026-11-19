<?php

declare(strict_types=1);

namespace App\Command;

use App\Engine\DecisionClosedException;
use App\Engine\DecisionDesk;
use App\Repository\DecisionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'reflex:decide', description: 'Approves, rejects, answers or declines a decision of the inbox as a person.')]
final readonly class DecideCommand
{
    public function __construct(
        private DecisionRepository $decisions,
        private UserRepository $users,
        private DecisionDesk $desk,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Decision id.')] string $decision,
        #[Argument('approve, reject, answer or decline.')] string $action,
        #[Option('Email of the person deciding.')] string $as = 'alice@polarcoffee.test',
        #[Option('Note for the agent.')] ?string $note = null,
        #[Option('Answer to a server question, as a JSON object.')] ?string $answer = null,
    ): int {
        $found = Uuid::isValid($decision) ? $this->decisions->find(Uuid::fromString($decision)) : null;
        $user = $this->users->findOneByEmail($as);
        if (null === $found || null === $user) {
            $io->error(null === $found ? 'No such decision.' : 'No such person.');

            return Command::INVALID;
        }

        try {
            match ($action) {
                'approve' => $io->success($this->desk->approve($found, $user, $note) ? 'Approved; it runs.' : 'Approval recorded; it needs more.'),
                'reject' => $this->desk->reject($found, $user, $note),
                'decline' => $this->desk->decline($found, $user, $note),
                'answer' => $this->desk->answer($found, $user, self::object($answer)),
                default => throw new \InvalidArgumentException('Use approve, reject, answer or decline.'),
            };
        } catch (DecisionClosedException|\InvalidArgumentException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s: %s.', $found->getToolName(), $found->getStatus()));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(?string $json): array
    {
        $value = json_decode((string) $json, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('Give --answer as a JSON object.');
        }

        $object = [];
        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }
}
