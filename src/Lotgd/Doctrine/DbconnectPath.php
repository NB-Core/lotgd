<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

/**
 * Where the game's database configuration lives.
 */
final class DbconnectPath
{
    /**
     * Locate the dbconnect.php the game should read.
     *
     * The file must resolve to a place the game owns: the game directory, or
     * the state directory a container keeps its configuration in. The Docker
     * image links dbconnect.php into its persistent state volume
     * (LOTGD_STATE_PATH), outside the read-only game directory, and that link
     * is the only way the file reaches there.
     *
     * @return string|null The resolved path, or null when the file is missing or resolves elsewhere
     */
    public static function resolve(string $rootDir, ?string $statePath): ?string
    {
        $resolved = realpath($rootDir . '/dbconnect.php');
        if ($resolved === false) {
            return null;
        }

        $allowed = [realpath($rootDir)];
        if ($statePath !== null && trim($statePath) !== '') {
            $allowed[] = realpath($statePath);
        }
        foreach ($allowed as $directory) {
            if ($directory !== false && str_starts_with($resolved, $directory . DIRECTORY_SEPARATOR)) {
                return $resolved;
            }
        }

        return null;
    }
}
