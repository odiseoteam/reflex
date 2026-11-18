<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testCreatesThenPromotesAUser(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('reflex:user:create'));

        $tester->execute(['email' => 'Ann@Example.com', '--password' => 'long enough']);
        $tester->assertCommandIsSuccessful();
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('ann@example.com');
        self::assertNotNull($user);
        self::assertSame('Ann', $user->getName());
        self::assertSame(['ROLE_VIEWER'], $user->getRoles());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'long enough'));

        $tester->execute(['email' => 'ann@example.com', '--password' => 'another one', '--admin' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(['ROLE_ADMIN'], $user->getRoles());

        self::assertSame(2, $tester->execute(['email' => 'ann@example.com', '--password' => 'short']));
    }
}
