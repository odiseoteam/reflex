<?php

declare(strict_types=1);

namespace App\Entity;

enum DecisionKind: string
{
    /** The agent wants to call a tool that needs approval. */
    case Proposal = 'proposal';
    /** A server asked for input in the middle of a call. */
    case Question = 'question';
}
