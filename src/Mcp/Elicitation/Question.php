<?php

declare(strict_types=1);

namespace App\Mcp\Elicitation;

use Mcp\Schema\Request\ElicitRequest;

/**
 * A form a server asked to fill in. Its key identifies it when the call is repeated.
 */
final readonly class Question
{
    /**
     * @param array<string, mixed> $schema requested schema, flat object of primitive properties
     */
    public function __construct(
        public string $key,
        public string $message,
        public array $schema,
    ) {
    }

    public static function fromRequest(ElicitRequest $request): self
    {
        $schema = null === $request->requestedSchema ? ['type' => 'object', 'properties' => []] : $request->requestedSchema->jsonSerialize();
        /** @var array<string, mixed> $schema */
        $schema = json_decode(json_encode($schema, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return new self(self::keyFor($request->message, $schema), $request->message, $schema);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function keyFor(string $message, array $schema): string
    {
        return hash('sha256', $message."\n".json_encode($schema, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array<mixed>> property name => its schema
     */
    public function properties(): array
    {
        $properties = $this->schema['properties'] ?? [];
        if (!\is_array($properties)) {
            return [];
        }

        $named = [];
        foreach ($properties as $name => $property) {
            if (\is_string($name) && \is_array($property)) {
                $named[$name] = $property;
            }
        }

        return $named;
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        $required = $this->schema['required'] ?? [];

        return \is_array($required) ? array_values(array_filter($required, is_string(...))) : [];
    }

    /**
     * @return array{key: string, message: string, schema: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'message' => $this->message, 'schema' => $this->schema];
    }

    /**
     * @param array{key: string, message: string, schema: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['message'], $data['schema']);
    }
}
