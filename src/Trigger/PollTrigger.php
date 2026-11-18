<?php

declare(strict_types=1);

namespace App\Trigger;

/**
 * Calls a read-only tool every few minutes and fires on what is new since the last call.
 */
final readonly class PollTrigger
{
    /**
     * @param array<string, mixed> $arguments
     * @param string|null          $itemKey   field that identifies an item of a list result;
     *                                        null compares the whole result instead
     */
    public function __construct(
        public string $connectionId,
        public string $tool,
        public array $arguments = [],
        public int $intervalMinutes = 5,
        public ?string $itemKey = 'id',
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            \is_string($data['connectionId'] ?? null) ? $data['connectionId'] : '',
            \is_string($data['tool'] ?? null) ? $data['tool'] : '',
            self::object($data['arguments'] ?? []) ?? [],
            \is_int($data['intervalMinutes'] ?? null) ? $data['intervalMinutes'] : 5,
            \is_string($data['itemKey'] ?? null) && '' !== $data['itemKey'] ? $data['itemKey'] : null,
        );
    }

    /**
     * @return array{connectionId: string, tool: string, arguments: array<string, mixed>, intervalMinutes: int, itemKey: string|null}
     */
    public function toArray(): array
    {
        return ['connectionId' => $this->connectionId, 'tool' => $this->tool, 'arguments' => $this->arguments, 'intervalMinutes' => $this->intervalMinutes, 'itemKey' => $this->itemKey];
    }

    /**
     * A JSON object as an array with string keys, or null for anything else.
     *
     * @return array<string, mixed>|null
     */
    public static function object(mixed $value): ?array
    {
        if (!\is_array($value) || (array_is_list($value) && [] !== $value)) {
            return null;
        }

        $object = [];
        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }
}
