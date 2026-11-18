<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'reflex:user:create', description: 'Creates a user, or updates the password and role of an existing one.')]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email to sign in with.')] string $email,
        #[Option('Display name; defaults to the part before @.')] ?string $name = null,
        #[Option('Password; asked for when omitted.')] ?string $password = null,
        #[Option('Can manage and decide, not only view.')] bool $admin = false,
    ): int {
        if (\count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()])) > 0) {
            $io->error('That is not a valid email.');

            return Command::INVALID;
        }

        $password ??= $io->askHidden('Password');
        if (!\is_string($password) || mb_strlen($password) < 8) {
            $io->error('The password needs at least 8 characters.');

            return Command::INVALID;
        }

        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $user = new User($email, $name ?? strstr($email, '@', true) ?: $email, $admin);
            $this->entityManager->persist($user);
        } else {
            $user->setAdmin($admin);
        }
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->entityManager->flush();

        $io->success(\sprintf('%s can sign in as %s.', $user->getEmail(), $admin ? 'admin' : 'viewer'));

        return Command::SUCCESS;
    }
}
