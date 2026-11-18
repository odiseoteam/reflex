<?php

declare(strict_types=1);

namespace App\Trigger;

/**
 * Event X happened and Y, for the same correlation value, didn't follow within the window.
 * E.g. "email_captured" without "order_completed" for the same customer.email in 30 minutes.
 */
final readonly class AbsenceTrigger
{
    public function __construct(
        /** Webhook source of both events. */
        public string $source,
        public string $whenEvent,
        public string $missingEvent,
        public int $withinMinutes,
        /** Dot path into both payloads, e.g. "customer.email". */
        public string $correlationPath,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            \is_string($data['source'] ?? null) ? $data['source'] : '',
            \is_string($data['whenEvent'] ?? null) ? $data['whenEvent'] : '',
            \is_string($data['missingEvent'] ?? null) ? $data['missingEvent'] : '',
            \is_int($data['withinMinutes'] ?? null) ? $data['withinMinutes'] : 30,
            \is_string($data['correlationPath'] ?? null) ? $data['correlationPath'] : '',
        );
    }

    /**
     * @return array{source: string, whenEvent: string, missingEvent: string, withinMinutes: int, correlationPath: string}
     */
    public function toArray(): array
    {
        return ['source' => $this->source, 'whenEvent' => $this->whenEvent, 'missingEvent' => $this->missingEvent, 'withinMinutes' => $this->withinMinutes, 'correlationPath' => $this->correlationPath];
    }

    /**
     * The correlation value in a payload, or null when it's missing or not a scalar.
     *
     * @param array<mixed> $payload
     */
    public function correlationIn(array $payload): ?string
    {
        $value = $payload;
        foreach (explode('.', $this->correlationPath) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return \is_scalar($value) && '' !== (string) $value ? mb_substr((string) $value, 0, 255) : null;
    }
}
