<?php

declare(strict_types=1);

namespace Lotgd\Installer;

/**
 * Server requirements the installer checks before it loads anything else.
 *
 * installer.php requires this file directly, before the Composer autoloader:
 * the autoloader's own platform check would stop an unsupported PHP with a
 * message about Composer rather than about the game. Keep the syntax to what
 * an old PHP can parse, so the version message is the one such a server
 * shows.
 *
 * Both database extensions are needed. The legacy layer talks to MySQL
 * through mysqli, and Doctrine (migrations, entities) through PDO. A server
 * with only mysqli passes the installer's connection test and then fails at
 * the migration stage with "could not find driver", which names neither the
 * extension nor the fix.
 */
final class Requirements
{
    public const MIN_PHP_VERSION = '8.3.0';

    /**
     * Extensions the game cannot run without, with the component that uses
     * each one.
     */
    public const REQUIRED_EXTENSIONS = [
        'mysqli' => 'the game\'s database layer',
        'pdo_mysql' => 'database migrations and Doctrine',
        // Composer's polyfill covers most mb_* functions, but not the
        // mb_ereg_replace() that commentary uses to break long words.
        'mbstring' => 'commentary and other multibyte text',
    ];

    /**
     * Describe every requirement the server does not meet.
     *
     * @param string|null   $phpVersion      Version to check; defaults to the running PHP
     * @param callable|null $extensionLoaded Returns whether an extension is loaded;
     *                                       defaults to extension_loaded()
     *
     * @return list<string> Plain-text messages, empty when everything is met
     */
    public static function unmet(?string $phpVersion = null, ?callable $extensionLoaded = null): array
    {
        $phpVersion = $phpVersion ?? PHP_VERSION;
        $extensionLoaded = $extensionLoaded ?? 'extension_loaded';

        $messages = [];
        if (version_compare($phpVersion, self::MIN_PHP_VERSION, '<')) {
            $messages[] = sprintf(
                'PHP %s or higher is required; this server runs PHP %s. Most hosting panels let you choose the PHP version per domain or folder.',
                self::MIN_PHP_VERSION,
                $phpVersion
            );
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension => $usedBy) {
            if (!$extensionLoaded($extension)) {
                $messages[] = sprintf(
                    'The PHP extension "%s" is missing; it is needed for %s. Enable it in your hosting panel\'s PHP settings (it is often listed under exactly this name) or ask your provider to enable it.',
                    $extension,
                    $usedBy
                );
            }
        }

        return $messages;
    }
}
