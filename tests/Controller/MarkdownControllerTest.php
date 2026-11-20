<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarkdownControllerTest extends WebTestCase
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

    public function testRendersMarkdownWithoutRawHtml(): void
    {
        $this->client->loginUser($this->user(true));
        $this->client->request('POST', '/markdown/preview', ['text' => "## Steps\n\n- Look the **customer** up\n\n<script>alert(1)</script>"]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Steps');
        self::assertSelectorTextContains('li strong', 'customer');
        self::assertSelectorNotExists('script');
    }

    public function testSaysWhenThereIsNothingToPreview(): void
    {
        $this->client->loginUser($this->user(true));
        $this->client->request('POST', '/markdown/preview', ['text' => '  ']);

        self::assertSelectorTextContains('p', 'Nothing to preview.');
    }

    public function testViewersCannotPreview(): void
    {
        $this->client->loginUser($this->user(false));
        $this->client->request('POST', '/markdown/preview', ['text' => 'Hi']);

        self::assertResponseStatusCodeSame(403);
    }

    private function user(bool $admin): User
    {
        $user = new User('alice@example.com', 'Alice', $admin);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
