<?php

declare(strict_types=1);

namespace Lotgd\Upgrade;

/**
 * Makes sure one request at a time applies the schema upgrade.
 */
interface SchemaUpgradeLock
{
    /**
     * Take the lock without waiting.
     *
     * @return bool False when another request already holds it
     */
    public function acquire(): bool;

    public function release(): void;
}
