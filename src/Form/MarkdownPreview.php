<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Markdown being written, to show as it will render.
 */
final readonly class MarkdownPreview
{
    public function __construct(
        #[Assert\Length(max: 10000)]
        public string $text = '',
    ) {
    }
}
