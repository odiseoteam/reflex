<?php

declare(strict_types=1);

namespace App\Engine\Recording;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Test\Recording\ResultSerializer;

/**
 * The model of one run segment, recording each answer of the live model or answering from a
 * recording. Answers are keyed by where the run is, not by the prompt, which carries ids and
 * times that differ on every run.
 */
final class RecordedPlatform implements PlatformInterface
{
    private int $calls = 0;

    public function __construct(
        private readonly ?PlatformInterface $live,
        private readonly PlatformInterface $catalog,
        private readonly Recordings $recordings,
        private readonly string $reflex,
        private readonly string $moment,
        /** With a live model, ask it only for answers not recorded yet. */
        private readonly bool $fill = false,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $key = $this->moment.'#'.++$this->calls;

        $answer = null === $this->live || $this->fill ? $this->recordings->find($this->reflex, $key) : null;
        if (null !== $answer) {
            $result = self::unserialize($answer);
        } elseif (null !== $this->live) {
            $result = $this->live->invoke($model, $input, $options)->getResult();
            $this->recordings->save($this->reflex, $key, self::serialize($result));
        } else {
            throw new NoRecordingException(\sprintf('"%s" has no recorded answer for %s. Set ANTHROPIC_API_KEY to run it with the live model.', $this->reflex, $key));
        }

        return self::deferred($result, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->catalog->getModelCatalog();
    }

    /**
     * Symfony AI's serializer has no multipart or thinking results, which Anthropic answers with.
     *
     * @return array<string, mixed>
     */
    private static function serialize(ResultInterface $result): array
    {
        if ($result instanceof ThinkingResult) {
            return ['type' => 'thinking', 'content' => $result->getContent(), 'signature' => $result->getSignature()];
        }
        if (!$result instanceof MultiPartResult) {
            return ResultSerializer::toArray($result);
        }

        $whole = ['type' => 'multipart', 'parts' => array_map(self::serialize(...), $result->getContent())];

        // The metadata (token usage) goes through a text result, the serializer's only way in.
        $carrier = new TextResult('');
        $carrier->getMetadata()->set($result->getMetadata()->all());
        $withMetadata = ResultSerializer::toArray($carrier);
        if (isset($withMetadata['metadata'])) {
            $whole['metadata'] = $withMetadata['metadata'];
        }

        return $whole;
    }

    /**
     * @param array<string, mixed> $answer
     */
    private static function unserialize(array $answer): ResultInterface
    {
        if ('thinking' === ($answer['type'] ?? null)) {
            return new ThinkingResult(\is_string($answer['content'] ?? null) ? $answer['content'] : null, \is_string($answer['signature'] ?? null) ? $answer['signature'] : null);
        }
        if ('multipart' !== ($answer['type'] ?? null)) {
            return ResultSerializer::fromArray($answer);
        }

        $parts = [];
        foreach (\is_array($answer['parts'] ?? null) ? $answer['parts'] : [] as $part) {
            if (\is_array($part)) {
                $parts[] = self::unserialize(self::fields($part));
            }
        }
        if ([] === $parts) {
            throw new NoRecordingException('A recorded multipart answer has no parts.');
        }
        $result = new MultiPartResult($parts);
        if (isset($answer['metadata'])) {
            $carrier = ResultSerializer::fromArray(['type' => 'text', 'content' => '', 'metadata' => $answer['metadata']]);
            $result->getMetadata()->set($carrier->getMetadata()->all());
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function fields(array $value): array
    {
        $fields = [];
        foreach ($value as $key => $item) {
            $fields[(string) $key] = $item;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function deferred(ResultInterface $result, array $options): DeferredResult
    {
        $raw = $result->getRawResult() ?? new InMemoryRawResult(['text' => $result->getContent()], [], (object) ['text' => $result->getContent()]);

        return new DeferredResult(new PlainConverter($result), $raw, $options);
    }
}
