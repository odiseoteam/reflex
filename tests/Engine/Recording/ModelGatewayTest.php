<?php

declare(strict_types=1);

namespace App\Tests\Engine\Recording;

use App\Engine\Recording\ModelGateway;
use App\Engine\Recording\RecordedPlatform;
use App\Entity\IncomingEvent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Repository\DecisionRepository;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Open models go to Hugging Face, the others to Anthropic, each replayed without its own key.
 */
final class ModelGatewayTest extends KernelTestCase
{
    public function testEachModelUsesItsProviderAndItsKey(): void
    {
        $anthropic = MockPlatformFactory::createPlatform(static fn () => new TextResult('claude'));
        $huggingFace = MockPlatformFactory::createPlatform(static fn () => new TextResult('open'));
        $gateway = new ModelGateway($anthropic, $huggingFace, self::getContainer()->get(DecisionRepository::class), 'auto', 'sk-key', '', sys_get_temp_dir());

        self::assertSame($anthropic, $gateway->for($this->runOf(Reflex::DEFAULT_MODEL)));
        self::assertSame(ModelGateway::LIVE, $gateway->mode(Reflex::DEFAULT_MODEL));

        self::assertSame(ModelGateway::REPLAY, $gateway->mode(Reflex::OPEN_MODEL), 'There is no Hugging Face token.');
        self::assertInstanceOf(RecordedPlatform::class, $gateway->for($this->runOf(Reflex::OPEN_MODEL)));
        self::assertTrue($gateway->isReplaying($this->runOf(Reflex::OPEN_MODEL)));
    }

    public function testAnExplicitModeAppliesToEveryModel(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => new TextResult(''));
        $gateway = new ModelGateway($platform, $platform, self::getContainer()->get(DecisionRepository::class), ModelGateway::REPLAY, 'sk-key', 'hf-key', sys_get_temp_dir());

        self::assertSame(ModelGateway::REPLAY, $gateway->mode(Reflex::DEFAULT_MODEL));
        self::assertSame(ModelGateway::REPLAY, $gateway->mode(Reflex::OPEN_MODEL));
    }

    public function testRunsByHandOnlyWithTheLiveModelOrARecordingOfOne(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => new TextResult(''));
        $recordings = self::getContainer()->getParameter('kernel.project_dir').'/demo/recordings';
        $replaying = new ModelGateway($platform, $platform, self::getContainer()->get(DecisionRepository::class), 'auto', '', '', $recordings);
        $live = new ModelGateway($platform, $platform, self::getContainer()->get(DecisionRepository::class), 'auto', 'sk-key', '', $recordings);

        $digest = new Reflex('Weekly digest', Reflex::SCHEDULE_SOURCE, Reflex::ANY_EVENT, 'x');
        $payments = new Reflex('Rescue failed payments', 'generic', 'payment.failed', 'x');

        self::assertTrue($replaying->canRunByHand($digest), 'Its run by hand is recorded.');
        self::assertFalse($replaying->canRunByHand($payments), 'Only its events are recorded.');
        self::assertTrue($live->canRunByHand($payments));
    }

    public function testRecordingsOfAnEventAboutACustomerAreKeptPerCustomer(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => new TextResult(''));
        $recordings = self::getContainer()->getParameter('kernel.project_dir').'/demo/recordings';
        $gateway = new ModelGateway($platform, $platform, self::getContainer()->get(DecisionRepository::class), ModelGateway::REPLAY, '', '', $recordings);
        $reflex = new Reflex('Rescue failed payments', 'generic', 'payment.failed', 'x');

        $ana = new Run($reflex, new IncomingEvent('generic', 'a', 'payment.failed', ['customer' => ['email' => 'ana@example.com']]));
        $nobody = new Run($reflex, new IncomingEvent('generic', 'n', 'payment.failed', ['customer' => ['email' => 'nobody@example.com']]));

        self::assertTrue($gateway->for($ana)->invoke(Reflex::DEFAULT_MODEL, 'x')->getResult()->getMetadata()->has('token_usage'), 'Ana\'s first answer is recorded.');
        $this->expectExceptionMessage('payment.failed[nobody@example.com]/1#1');
        $gateway->for($nobody)->invoke(Reflex::DEFAULT_MODEL, 'x');
    }

    private function runOf(string $model): Run
    {
        return new Run(new Reflex('Reply to bad reviews', Reflex::POLL_SOURCE, Reflex::ANY_EVENT, 'x', $model));
    }
}
