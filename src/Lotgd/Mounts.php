<?php

declare(strict_types=1);

/**
 * Access to mount data.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;

class Mounts
{
    /**
     * Instance for singleton pattern.
     */
    private static ?self $instance = null;

    /**
     * Current player mount data.
     *
     * @var array<string,mixed>
     */
    private array $playerMount = [];

    /**
     * Retrieve mount information from the database.
     *
     * @param int $horse Mount id
     *
     * @return array<string,mixed>
     */
    public static function getmount(int $horse = 0): array
    {
        $sql = 'SELECT * FROM ' . Database::prefix('mounts') . " WHERE mountid='$horse'";
        $result = Database::queryCached($sql, "mountdata-$horse", 3600);
        if (Database::numRows($result) > 0) {
            return Database::fetchAssoc($result);
        }

        return [];
    }

    /**
     * Get the Mounts singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Set the current player mount.
     *
     * @param array<string,mixed> $mount Mount data
     */
    public function setPlayerMount(array $mount): void
    {
        $this->playerMount = $mount;
    }

    /**
     * Retrieve the current player mount.
     *
     * @return array<string,mixed>
     */
    public function getPlayerMount(): array
    {
        return $this->playerMount;
    }

    /**
     * Load a mount from the database and set it as the current player mount.
     *
     * @param int $horse Mount id
     *
     * @return array<string,mixed>
     */
    public function loadPlayerMount(int $horse): array
    {
        $this->playerMount = self::getmount($horse);

        return $this->playerMount;
    }
}
