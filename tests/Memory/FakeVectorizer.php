<?php

declare(strict_types=1);

namespace App\Tests\Memory;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\DocumentInterface;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * Stands in for the embeddings server: words of the event line hashed into a unit vector,
 * so the same event lands at distance 0 and unrelated ones far apart.
 */
final class FakeVectorizer implements VectorizerInterface
{
    private const int DIMENSIONS = 64;

    /**
     * @return Vector|list<Vector>
     */
    public function vectorize(string|\Stringable|DocumentInterface|array $values, array $options = []): Vector|array
    {
        if (\is_array($values)) {
            return array_values(array_map(fn (mixed $value): Vector => $this->embed($value), $values));
        }

        return $this->embed($values);
    }

    private function embed(mixed $value): Vector
    {
        if (!\is_string($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('Only texts are embedded here.');
        }

        $line = strtok((string) $value, "\n") ?: '';
        $data = array_fill(0, self::DIMENSIONS, 0.0);
        foreach (preg_split('/\W+/', strtolower($line), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            ++$data[crc32($word) % self::DIMENSIONS];
        }

        $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $data))) ?: 1.0;

        return new Vector(array_values(array_map(static fn (float $x): float => $x / $norm, $data)));
    }
}
