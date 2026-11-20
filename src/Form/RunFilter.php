<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RunStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Which runs the list shows, from its query string.
 */
final readonly class RunFilter
{
    public function __construct(
        public ?RunStatus $status = null,
        #[Assert\Uuid]
        public ?string $reflex = null,
    ) {
    }
}
