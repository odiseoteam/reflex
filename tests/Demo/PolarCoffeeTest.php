<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\PolarCoffee;
use App\Demo\Scenarios;
use App\Entity\Connection;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\ToolPolicy;
use App\Entity\User;
use App\Repository\ReflexRepository;
use Demo\PolarCoffee\Store;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Setting up the demo company, and what its scenarios make happen.
 */
final class PolarCoffeeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $dataDirectory;

    protected function setUp(): void
    {
        $this->dataDirectory = sys_get_temp_dir().'/polar-coffee-'.bin2hex(random_bytes(4));
        putenv('DEMO_DATA_DIR='.$this->dataDirectory);

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        putenv('DEMO_DATA_DIR');
        array_map(unlink(...), glob($this->dataDirectory.'/*.json') ?: []);
        parent::tearDown();
    }

    public function testSetupCreatesThePeopleConnectionsAndAReflexPerTriggerOnlyOnce(): void
    {
        $result = $this->polarCoffee()->install('secret', resetData: true);
        $this->polarCoffee()->install('secret');

        self::assertSame(['alice@polarcoffee.test', 'bob@polarcoffee.test', 'vera@polarcoffee.test'], $result['people']);
        self::assertSame(['Shop' => 'failed', 'Support' => 'failed'], $result['connections'], 'Nothing answers in tests.');
        self::assertCount(3, $this->entityManager->getRepository(User::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(Connection::class)->findAll());

        $reflexes = $this->entityManager->getRepository(Reflex::class)->findAll();
        self::assertCount(5, $reflexes);
        self::assertEqualsCanonicalizing(
            ['generic', Reflex::SUBSCRIPTION_SOURCE, Reflex::ABSENCE_SOURCE, Reflex::SCHEDULE_SOURCE, Reflex::POLL_SOURCE],
            array_map(static fn (Reflex $reflex): string => $reflex->getTriggerSource(), $reflexes),
        );

        $failedPayments = $this->reflex('Failed payments');
        self::assertSame(ToolPolicy::Approval, $failedPayments->policyFor('shop_issue_coupon'));
        self::assertSame(2, $failedPayments->approvalsFor('shop_refund_order'));
        self::assertSame(ToolPolicy::Forbidden, $this->reflex('Weekly digest')->policyFor('shop_refund_order'));
        self::assertSame([PolarCoffee::WATCHED_TICKET], $this->reflex('Ticket follow-up')->getSubscriptionTrigger()?->resourceUris);
    }

    public function testAFailedPaymentChangesTheShopAndRunsTheReflex(): void
    {
        $this->polarCoffee()->install('secret', resetData: true);

        $said = $this->scenarios()->play('payment-failed', 'bruno');

        self::assertStringContainsString('Bruno Díaz: the payment of PC-1059 (38.00 EUR) failed', $said);
        $order = Store::find(Store::fromEnvironment()->read()['orders'], 'id', 'PC-1059');
        self::assertSame('payment_failed', $order['status'] ?? null);

        $runs = $this->entityManager->getRepository(Run::class)->findAll();
        self::assertCount(1, $runs);
        self::assertSame('Failed payments', $runs[0]->getReflex()->getName());
        $event = $runs[0]->getEvent();
        self::assertInstanceOf(IncomingEvent::class, $event);
        self::assertSame('payment.failed', $event->getType());
        self::assertSame(['name' => 'Bruno Díaz', 'email' => 'bruno@example.com'], $event->getPayload()['customer']);
    }

    public function testAPaidOrderWaitsForItsShipment(): void
    {
        $this->polarCoffee()->install('secret', resetData: true);

        $this->scenarios()->play('order-paid');
        self::assertSame([], $this->entityManager->getRepository(Run::class)->findAll(), 'It runs only if no shipment follows.');

        self::assertStringContainsString('PC-1059 shipped', $this->scenarios()->play('order-shipped'));

        $this->expectException(\InvalidArgumentException::class);
        $this->scenarios()->play('order-shipped');
    }

    private function reflex(string $name): Reflex
    {
        $reflex = self::getContainer()->get(ReflexRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($reflex);

        return $reflex;
    }

    private function polarCoffee(): PolarCoffee
    {
        return self::getContainer()->get(PolarCoffee::class);
    }

    private function scenarios(): Scenarios
    {
        return self::getContainer()->get(Scenarios::class);
    }
}
