<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\Precedent;

final readonly class Recollection
{
    /**
     * @param float $distance cosine distance to the case at hand, lower is closer
     */
    public function __construct(
        public Precedent $precedent,
        public float $distance,
    ) {
    }
}
