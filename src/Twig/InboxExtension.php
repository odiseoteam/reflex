<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\DecisionRepository;
use Twig\Attribute\AsTwigFunction;

final readonly class InboxExtension
{
    public function __construct(private DecisionRepository $decisions)
    {
    }

    #[AsTwigFunction('pending_decisions')]
    public function pendingDecisions(): int
    {
        return $this->decisions->countPending();
    }
}
