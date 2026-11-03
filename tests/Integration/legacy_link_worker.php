<?php

/**
 * The legacy mysqli link released and opened again, for
 * LegacyLinkMariaDbTest. Run from an installed copy of the game.
 *
 * The argument is a JSON spec with `root` (the installed game) and `wait`
 * (a file to wait for after the release, while the test looks at the
 * server's connections). Prints JSON: the connection ids before and after,
 * and whether a query after the release worked.
 */

declare(strict_types=1);

use Lotgd\MySQL\Database;

$spec = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
chdir($spec['root']);
require $spec['root'] . '/autoload.php';
$config = require $spec['root'] . '/dbconnect.php';

Database::connect((string) $config['DB_HOST'], (string) $config['DB_USER'], (string) $config['DB_PASS']);
Database::selectDb((string) $config['DB_NAME']);
Database::setCharset('utf8mb4');

// The mysqli link itself: Database::query() goes through Doctrine once
// dbconnect.php exists, so only the link's own functions (escape, error,
// insertId) reach it.
$link = static fn (): ?\mysqli => \Closure::bind(fn () => $this->link, Database::getInstance(), Database::getInstance()::class)();

$before = (int) mysqli_thread_id($link());
Database::releaseLegacyLink();
$released = $link() === null;
file_put_contents($spec['released'], (string) $before);
$until = microtime(true) + 30;
while (!file_exists($spec['wait']) && microtime(true) < $until) {
    usleep(10000);
}

// Used again: escape() opens the link with what it had.
$escaped = Database::escape("it's");
$after = $link() instanceof \mysqli ? (int) mysqli_thread_id($link()) : 0;
$database = $after > 0 ? (mysqli_fetch_assoc(mysqli_query($link(), 'SELECT DATABASE() AS d'))['d'] ?? '') : '';
$charset = $after > 0 ? mysqli_character_set_name($link()) : '';
echo json_encode(['before' => $before, 'released' => $released, 'after' => $after, 'escaped' => $escaped, 'charset' => $charset, 'database' => $database]);
