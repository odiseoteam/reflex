<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Decision;
use App\Entity\DecisionKind;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\User;
use App\Entity\Waitpoint;
use App\Entity\WaitpointKind;
use App\Mcp\Elicitation\Question;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DecisionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Run $run;
    private Waitpoint $waitpoint;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $reflex = new Reflex('Triage', 'generic', '*', 'Close duplicates.');
        $event = new IncomingEvent('generic', 'delivery-1', 'issue.opened', []);
        $this->run = new Run($reflex, $event);
        $this->run->start();
        $this->waitpoint = new Waitpoint($this->run, WaitpointKind::Decisions);
        $this->run->wait('Proposed something.', []);
        foreach ([$reflex, $event, $this->run, $this->waitpoint] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testSignInIsRequired(): void
    {
        $this->client->request('GET', '/inbox');

        self::assertResponseRedirects('/login');
    }

    public function testSignsInWithAPassword(): void
    {
        $user = $this->user('carol@example.com', admin: false);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user->setPassword($hasher->hashPassword($user, 'correct horse'));
        $this->entityManager->flush();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'carol@example.com', '_password' => 'correct horse']);

        self::assertResponseRedirects('/inbox');
    }

    public function testAViewerSeesButCannotDecide(): void
    {
        $decision = $this->proposal();
        $this->client->loginUser($this->user('viewer@example.com', admin: false));

        $this->client->request('GET', '/decisions/'.$decision->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Waiting for an admin to decide.');
        self::assertSelectorNotExists('button[formaction$="/approve"]');
    }

    public function testTwoAdminsApproveATwoPersonDecision(): void
    {
        $decision = $this->proposal(requiredApprovals: 2);

        $this->client->loginUser($this->user('alice@example.com'));
        $this->client->request('GET', '/decisions/'.$decision->getId());
        $this->client->submitForm('Approve', ['note' => 'Fine by me.']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'It needs 1 more.');
        self::assertSelectorTextContains('main', '1 of 2 approvals');

        self::assertSelectorTextContains('main', 'You already voted.');

        $this->client->loginUser($this->user('bob@example.com'));
        $this->client->request('GET', '/decisions/'.$decision->getId());
        $this->client->submitForm('Approve and run');
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Approved. It runs exactly as shown.');
        self::assertSame(Decision::APPROVED, $this->reload($decision)->getStatus());
    }

    public function testAnAdminAnswersAServerQuestion(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'label' => ['type' => 'string', 'title' => 'Label', 'enum' => ['bug', 'duplicate']],
            'notify' => ['type' => 'boolean', 'title' => 'Notify the author'],
            'priority' => ['type' => 'integer', 'title' => 'Priority', 'minimum' => 1, 'maximum' => 3],
        ], 'required' => ['label', 'priority']];
        $question = new Question(Question::keyFor('How should it be labelled?', $schema), 'How should it be labelled?', $schema);
        $decision = new Decision($this->run, $this->waitpoint, null, 'tracker_label', 'label', ['id' => 7], 'The server asked for input while running it.', DecisionKind::Question, question: $question);
        $this->entityManager->persist($decision);
        $this->entityManager->flush();

        $this->client->loginUser($this->user('alice@example.com'));
        $this->client->request('GET', '/decisions/'.$decision->getId());
        self::assertSelectorTextContains('main', 'How should it be labelled?');

        $this->client->submitForm('Answer and continue', ['answer[label]' => 'duplicate', 'answer[priority]' => '9']);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Answer and continue', ['answer[label]' => 'duplicate', 'answer[priority]' => '2', 'answer[notify]' => true]);
        self::assertResponseRedirects();

        $decision = $this->reload($decision);
        self::assertSame(Decision::APPROVED, $decision->getStatus());
        self::assertSame([$question->key => ['label' => 'duplicate', 'notify' => true, 'priority' => 2]], $decision->getAnswers());
    }

    private function proposal(int $requiredApprovals = 1): Decision
    {
        $decision = new Decision($this->run, $this->waitpoint, null, 'tracker_close_issue', 'close_issue', ['id' => 7], 'Duplicate of #3.', requiredApprovals: $requiredApprovals);
        $this->entityManager->persist($decision);
        $this->entityManager->flush();

        return $decision;
    }

    private function user(string $email, bool $admin = true): User
    {
        $user = new User($email, ucfirst(strstr($email, '@', true) ?: $email), $admin);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function reload(Decision $decision): Decision
    {
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(Decision::class, $decision->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
