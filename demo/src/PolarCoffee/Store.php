<?php

declare(strict_types=1);

namespace Demo\PolarCoffee;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * The state of Polar Coffee Store's shop and helpdesk, a JSON file both demo servers and the
 * simulator share. Every change runs under an exclusive lock.
 *
 * @phpstan-type Data array<string, list<array<string, mixed>>>
 */
final class Store
{
    private const string SEED = __DIR__.'/../../data/polar-coffee.json';

    private readonly string $file;
    private ?NotificationBusInterface $bus = null;

    public function __construct(
        private readonly string $directory,
    ) {
        $this->file = $directory.'/polar-coffee.json';
    }

    public static function fromEnvironment(): self
    {
        $directory = getenv('DEMO_DATA_DIR');

        return new self(\is_string($directory) && '' !== $directory ? $directory : \dirname(__DIR__, 3).'/var/demo');
    }

    /**
     * Back to the seed data.
     */
    public function reset(): void
    {
        $this->ensureDirectory();
        $seed = file_get_contents(self::SEED);
        if (false === $seed) {
            throw new \RuntimeException('The seed data is missing.');
        }
        file_put_contents($this->file, $seed, \LOCK_EX);
    }

    /**
     * @return Data
     */
    public function read(): array
    {
        if (!is_file($this->file)) {
            $this->reset();
        }

        return self::decode((string) file_get_contents($this->file));
    }

    /**
     * @template T
     *
     * @param \Closure(Data): array{Data, T} $change returns the new data and a result
     *
     * @return T
     */
    public function change(\Closure $change): mixed
    {
        if (!is_file($this->file)) {
            $this->reset();
        }

        $handle = fopen($this->file, 'c+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the demo data.');
        }

        try {
            flock($handle, \LOCK_EX);
            [$data, $result] = $change(self::decode((string) stream_get_contents($handle)));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The bus subscription streams read, shared by every server worker and the simulator.
     */
    public function bus(): NotificationBusInterface
    {
        $this->ensureDirectory();

        return $this->bus ??= new Psr16NotificationBus(new Psr16Cache(new FilesystemAdapter('bus', 0, $this->directory)));
    }

    public function publish(Notification $notification): void
    {
        $this->bus()->publish($notification);
    }

    public function resourceChanged(string $uri): void
    {
        $this->publish(new ResourceUpdatedNotification($uri));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    public static function find(array $rows, string $key, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>>                           $rows
     * @param \Closure(array<string, mixed>): array<string, mixed> $update
     *
     * @return list<array<string, mixed>>
     */
    public static function update(array $rows, string $key, mixed $value, \Closure $update): array
    {
        return array_map(static fn (array $row): array => ($row[$key] ?? null) === $value ? $update($row) : $row, $rows);
    }

    /**
     * @param array<mixed> $row
     */
    public static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<mixed> $row
     */
    public static function amount(array $row, string $key): float
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $row, string $key): array
    {
        $value = $row[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $fields = [];
                foreach ($item as $field => $fieldValue) {
                    $fields[(string) $field] = $fieldValue;
                }
                $rows[] = $fields;
            }
        }

        return $rows;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $this->directory));
        }
    }

    /**
     * @return Data
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('The demo data is not an object.');
        }

        $data = [];
        foreach (array_keys($decoded) as $table) {
            $data[(string) $table] = self::rows($decoded, (string) $table);
        }

        return $data;
    }
}
