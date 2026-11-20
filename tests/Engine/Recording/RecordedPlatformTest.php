<?php

declare(strict_types=1);

namespace App\Tests\Engine\Recording;

use App\Engine\Recording\NoRecordingException;
use App\Engine\Recording\RecordedPlatform;
use App\Engine\Recording\Recordings;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * What the live model answered at each moment of a run comes back the same without it.
 */
final class RecordedPlatformTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/recordings-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*.json') ?: []);
        @rmdir($this->directory);
    }

    public function testAnswersRecordedLiveAreReplayedInOrder(): void
    {
        $answers = [
            new MultiPartResult([new ThinkingResult('She is a subscriber.', 'sig'), new TextResult('Looking her up.'), new ToolCallResult([new ToolCall('call-1', 'shop_find_customer', ['email' => 'ana@example.com'])])]),
            new TextResult('I proposed a coupon.'),
        ];
        $live = MockPlatformFactory::createPlatform(static function () use (&$answers): MultiPartResult|TextResult {
            return array_shift($answers) ?? new TextResult('unexpected');
        });

        $recording = $this->platform($live);
        $recording->invoke('claude-sonnet-5-5', 'first');
        $recording->invoke('claude-sonnet-5-5', 'second');

        $replay = $this->platform(null);
        $first = $replay->invoke('claude-sonnet-5-5', 'prompts may differ')->getResult();
        self::assertInstanceOf(MultiPartResult::class, $first);
        self::assertSame('Looking her up.', $first->asText());
        self::assertSame(['email' => 'ana@example.com'], $first->asToolCallResult()?->getContent()[0]->getArguments());
        self::assertInstanceOf(ThinkingResult::class, $first->getContent()[0]);
        self::assertSame('I proposed a coupon.', $replay->invoke(new Model('claude-sonnet-5-5'), 'x')->getResult()->getContent());

        $this->expectException(NoRecordingException::class);
        $replay->invoke('claude-sonnet-5-5', 'one call too many');
    }

    public function testFillingAsksTheLiveModelOnlyForWhatIsNotRecorded(): void
    {
        $this->platform(MockPlatformFactory::createPlatform(static fn () => new TextResult('recorded')))->invoke('claude-sonnet-5-5', 'first');

        $calls = 0;
        $live = MockPlatformFactory::createPlatform(static function () use (&$calls): TextResult {
            ++$calls;

            return new TextResult('filled in');
        });
        $filling = new RecordedPlatform($live, $live, new Recordings($this->directory), 'Rescue failed payments', 'payment.failed/1', fill: true);

        self::assertSame('recorded', $filling->invoke('claude-sonnet-5-5', 'x')->getResult()->getContent());
        self::assertSame('filled in', $filling->invoke('claude-sonnet-5-5', 'x')->getResult()->getContent());
        self::assertSame(1, $calls);
        self::assertSame('filled in', (new Recordings($this->directory))->find('Rescue failed payments', 'payment.failed/1#2')['content'] ?? null, 'What was missing is now recorded.');
    }

    public function testAnotherMomentOfTheRunHasItsOwnAnswers(): void
    {
        $this->expectException(NoRecordingException::class);
        $this->expectExceptionMessage('payment.failed/2/shop_issue_coupon=rejected#1');

        (new RecordedPlatform(null, MockPlatformFactory::createPlatform(static fn () => new TextResult('')), new Recordings($this->directory), 'Rescue failed payments', 'payment.failed/2/shop_issue_coupon=rejected'))
            ->invoke('claude-sonnet-5-5', 'x');
    }

    private function platform(?PlatformInterface $live): RecordedPlatform
    {
        return new RecordedPlatform($live, MockPlatformFactory::createPlatform(static fn () => new TextResult('')), new Recordings($this->directory), 'Rescue failed payments', 'payment.failed/1');
    }
}
