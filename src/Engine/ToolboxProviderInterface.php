<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\Run;

/**
 * Gives a run the tools of its reflex's connections.
 */
interface ToolboxProviderInterface
{
    /**
     * @return list<ConnectedToolbox>
     */
    public function open(Run $run): array;

    /**
     * Closes what open() connected.
     */
    public function close(): void;
}
