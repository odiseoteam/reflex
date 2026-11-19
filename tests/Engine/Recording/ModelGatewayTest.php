<?php

declare(strict_types=1);

namespace App\Tests\Engine\Recording;

use App\Engine\Recording\ModelGateway;
use App\Engine\Recording\RecordedPlatform;
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

    private function runOf(string $model): Run
    {
        return new Run(new Reflex('Review watch', Reflex::POLL_SOURCE, Reflex::ANY_EVENT, 'x', $model));
    }
}
