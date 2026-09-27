<?php

declare(strict_types=1);

namespace Lotgd\Upgrade;

use Doctrine\DBAL\Connection;

/**
 * A MySQL named lock, held by the connection that took it.
 *
 * The server releases a named lock by itself when the connection closes, so a
 * request that dies halfway through an upgrade cannot leave the game locked.
 * The name carries the database name because named locks are server-wide and
 * one MySQL server may host several games.
 */
final class MysqlSchemaUpgradeLock implements SchemaUpgradeLock
{
    private bool $held = false;

    public function __construct(private Connection $connection)
    {
    }

    public function acquire(): bool
    {
        $this->held = (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$this->name()]) === 1;

        return $this->held;
    }

    public function release(): void
    {
        if ($this->held) {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->name()]);
            $this->held = false;
        }
    }

    private function name(): string
    {
        // MySQL limits lock names to 64 characters.
        return substr('lotgd_schema_upgrade.' . (string) $this->connection->getDatabase(), 0, 64);
    }
}
