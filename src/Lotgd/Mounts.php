<?php

declare(strict_types=1);

/**
 * Access to mount data.
 */

namespace Lotgd;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;

class Mounts
{
    public const GRANT_SUCCESS = 'success';
    public const GRANT_NOT_FOUND = 'not_found';
    public const GRANT_INVALID_BUFF = 'invalid_buff';

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

    /**
     * Grant a stored mount to the current user after validating its buff.
     *
     * The session is deliberately changed only after both the database row and
     * its serialized buff have been validated.
     */
    public static function grantToCurrentUser(int $mountId): string
    {
        global $session;

        $connection = Database::getDoctrineConnection();
        $sql = 'SELECT * FROM ' . Database::prefix('mounts') . ' WHERE mountid = :mountId';
        $row = $connection->executeQuery(
            $sql,
            ['mountId' => $mountId],
            ['mountId' => ParameterType::INTEGER]
        )->fetchAssociative();

        if ($row === false) {
            error_log("Denied mount give: mount record not found (mountId={$mountId})");
            return self::GRANT_NOT_FOUND;
        }

        $buff = Serialization::safeUnserialize($row['mountbuff'] ?? null);
        if (!is_array($buff)) {
            error_log("Denied mount give: stored buff is malformed (mountId={$mountId})");
            return self::GRANT_INVALID_BUFF;
        }

        if (($buff['schema'] ?? '') === '') {
            $buff['schema'] = 'mounts';
        }

        $session['user']['hashorse'] = $mountId;
        Buffs::applyBuff('mount', $buff);

        return self::GRANT_SUCCESS;
    }

    /**
     * Decode and validate a stored mount buff before populating the editor form.
     *
     * Serialization::safeUnserialize() disables class instantiation. Keeping the
     * array check here gives every mount-edit caller the same form-safe boundary.
     *
     * @return array{buff: array<mixed>, valid: bool}
     */
    public static function prepareBuffForEditor(mixed $storedBuff, int $mountId): array
    {
        $buff = Serialization::safeUnserialize($storedBuff);
        if (!is_array($buff)) {
            error_log("Invalid stored mount buff while editing mount (mountId={$mountId})");

            return ['buff' => [], 'valid' => false];
        }

        return ['buff' => $buff, 'valid' => true];
    }
}
