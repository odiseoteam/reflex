<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Decision;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\RunStepKind;
use App\Entity\User;
use App\Entity\Waitpoint;
use App\Entity\WaitpointKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testSignInIsRequired(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testShowsTodayPendingDecisionsReflexesAndActivity(): void
    {
        $reflex = new Reflex('Rescue failed payments', 'generic', 'payment.failed', 'Look the customer up.');
        $reflex->setLimits(1000, 10, 120);

        $failedPayment = new IncomingEvent('generic', 'evt-1', 'payment.failed', []);
        $finished = new Run($reflex, $failedPayment);
        $finished->start();
        $finished->addStep('k1', RunStepKind::ToolCall, 'shop_find_customer', [], '{"id": 7}');
        $finished->recordTokens(400);
        $finished->succeed('Looked Ana up.');

        $secondFailure = new IncomingEvent('generic', 'evt-2', 'payment.failed', []);
        $waiting = new Run($reflex, $secondFailure);
        $waiting->start();
        $waiting->addStep('k2', RunStepKind::Proposal, 'shop_issue_coupon', ['percent' => 10]);
        $waiting->wait('Proposed a coupon.', []);
        $waitpoint = new Waitpoint($waiting, WaitpointKind::Decisions);
        $decision = new Decision($waiting, $waitpoint, null, 'shop_issue_coupon', 'issue_coupon', ['percent' => 10], 'Loyal customer whose card failed.');

        foreach ([$reflex, $failedPayment, $finished, $secondFailure, $waiting, $waitpoint, $decision] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->client->loginUser($this->user());
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header', 'Polar Coffee Store');
        self::assertSelectorExists('header a[href="/"][aria-current="page"]');
        self::assertSelectorNotExists('nav[aria-label="Breadcrumb"]');
        self::assertSelectorTextContains('main', 'Pending decisions 1 to approve or reject');
        self::assertSelectorTextContains('main', 'Runs today 2 1 finished · 1 waiting');
        self::assertSelectorTextContains('main', 'Automatic actions 1 ran today without approval');
        self::assertSelectorTextContains('main', 'Tokens today 400');

        self::assertSame('Loyal customer whose card failed.', trim($crawler->filter('section')->eq(0)->filter('tbody td')->eq(0)->filter('div')->eq(0)->text()));
        self::assertCount(1, $crawler->filter('a:contains("View details")'));
        self::assertSelectorNotExists('main h2:contains("Reflexes")');

        $runs = $crawler->filter('main ol li')->each(static fn ($item): string => $item->text());
        $time = static fn (Run $run): string => $run->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('H:i');
        self::assertContains(\sprintf('Rescue failed payments Waiting Webhook · payment.failed · %s 1 proposed', $time($waiting)), $runs);
        self::assertContains(\sprintf('Rescue failed payments Succeeded Webhook · payment.failed · %s 1 ran', $time($finished)), $runs);

        $utc = $finished->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        self::assertSelectorExists(\sprintf('main ol time[datetime="%s"][data-controller="local-time"][data-local-time-format-value="time"]', $utc));
    }

    private function user(): User
    {
        $user = new User('alice@example.com', 'Alice (owner)', true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
