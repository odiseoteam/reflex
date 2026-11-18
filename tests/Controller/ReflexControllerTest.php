<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ReflexRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReflexControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $admin = new User('admin@example.com', 'Admin', true);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testCreatesAScheduledReflex(): void
    {
        $this->client->request('GET', '/reflexes/new');
        $fields = [
            'reflex[name]' => 'Weekly digest',
            'reflex[triggerSource]' => 'schedule',
            'reflex[triggerEvent]' => 'ignored',
            'reflex[cron]' => 'every monday',
            'reflex[instructions]' => 'Summarise the week across connections.',
        ];

        $this->client->submitForm('Save', $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Use a cron expression');

        $this->client->submitForm('Save', ['reflex[cron]' => '0 9 * * 1'] + $fields);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'On schedule 0 9 * * 1 UTC');

        $reflex = self::getContainer()->get(ReflexRepository::class)->findOneBy(['name' => 'Weekly digest']);
        self::assertNotNull($reflex);
        self::assertTrue($reflex->isScheduled());
        self::assertSame('*', $reflex->getTriggerEvent());
    }

    public function testAViewerCannotCreateReflexes(): void
    {
        $viewer = new User('viewer@example.com', 'Viewer');
        $this->entityManager->persist($viewer);
        $this->entityManager->flush();
        $this->client->loginUser($viewer);

        $this->client->request('GET', '/reflexes/new');
        self::assertResponseStatusCodeSame(403);
    }
}
