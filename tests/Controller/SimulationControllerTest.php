<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SimulationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $dataDirectory;

    protected function setUp(): void
    {
        $this->dataDirectory = sys_get_temp_dir().'/polar-coffee-'.bin2hex(random_bytes(4));
        putenv('DEMO_DATA_DIR='.$this->dataDirectory);

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->entityManager->persist(new Reflex('Rescue failed payments', 'generic', 'payment.failed', 'Look the customer up.'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        putenv('DEMO_DATA_DIR');
        array_map(unlink(...), glob($this->dataDirectory.'/*.json') ?: []);
        parent::tearDown();
    }

    public function testAnAdminSimulatesAnEventFromTheDashboard(): void
    {
        $this->client->loginUser($this->user(true));
        $crawler = $this->client->request('GET', '/');

        $form = $crawler->filter('#simulate button[value="payment-failed"]')->form();
        $form['customer'] = 'bruno';
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Bruno Díaz: the payment of PC-1102 (38.00 EUR) failed. "Rescue failed payments" runs.');

        $runs = $this->entityManager->getRepository(Run::class)->findAll();
        self::assertCount(1, $runs);
        self::assertSame('payment.failed', $runs[0]->getEvent()?->getType());
    }

    public function testAnUnknownScenarioIsRejected(): void
    {
        $this->client->loginUser($this->user(true));
        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('#simulate input[name="_token"]')->attr('value');

        $this->client->request('POST', '/simulate', ['_token' => $token, 'scenario' => 'meteor-strike']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->entityManager->getRepository(Run::class)->findAll());
    }

    public function testViewersCannotSimulate(): void
    {
        $this->client->loginUser($this->user(false));
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('#simulate');

        $this->client->request('POST', '/simulate', ['scenario' => 'payment-failed']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->entityManager->getRepository(Run::class)->findAll());
    }

    private function user(bool $admin): User
    {
        $user = new User('alice@example.com', 'Alice', $admin);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
