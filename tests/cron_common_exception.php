<?php

declare(strict_types=1);

namespace Lotgd\Tests\Cron;

require __DIR__ . '/../autoload.php';
require_once __DIR__ . '/Stubs/Functions.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;

global $settings, $argv, $mail_sent_count, $output;

$cacheDir = sys_get_temp_dir();
$settings = new DummySettings([
    'notify_on_error' => 1,
    'notify_address'  => 'admin@example.com',
    'gameadminemail'  => 'admin@example.com',
    'usedatacache'    => 1,
    'datacachepath'   => $cacheDir,
]);

$argv = [];

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

/**
 * cron.php is run from a directory of this process's own, not from the
 * repository.
 *
 * What this used to do was rename the repository's own common.php out of the
 * way, drop the throwing fixture in its place, and put it back from a shutdown
 * handler. That works right up until it does not:
 *
 *   - Two of these at once, and the second rename moves the *fixture* to
 *     common.php.bak, on top of the real file. The restore then puts the
 *     fixture back as common.php and the real one is gone. Not hypothetical:
 *     six concurrent runs of this test deleted common.php from the working
 *     tree, which is how this was found.
 *   - A kill between the rename and the shutdown handler leaves a checkout
 *     with no common.php at all.
 *
 * A test may not be able to damage the tree it is testing. So the fixture is
 * placed in a private directory instead, alongside a copy of cron.php -- a
 * copy and not a symlink, because PHP resolves symlinks for __DIR__, and it is
 * cron.php's own __DIR__ that decides which common.php it loads. Everything
 * else the run needs is symlinked, so the copy stays one file and cannot drift
 * from the real cron.php.
 */
/**
 * What this script exits with when it cannot even set itself up.
 *
 * Not 1: cron.php exits 1 on purpose when common.php throws, which is the
 * whole point of this run. Sharing the code would make "the cron reported its
 * failure correctly" and "the harness never got as far as running it"
 * indistinguishable to the caller.
 */
const SETUP_FAILED = 3;

$root = dirname(__DIR__);
$farm = sys_get_temp_dir() . '/lotgd-cron-exception-' . getmypid() . '-' . bin2hex(random_bytes(4));

if (!mkdir($farm, 0777, true) && !is_dir($farm)) {
    fwrite(STDERR, "could not create $farm\n");
    exit(SETUP_FAILED);
}

/**
 * Clears the directory away, and says so when it cannot.
 *
 * Registered here, straight after the directory exists and before anything is
 * put in it, so that a bail-out further down takes the directory with it. That
 * is one concrete way farms were surviving a run; whether it is the way the two
 * empty ones found in /tmp got there is not established, and the diagnostic
 * below is what will say so next time rather than a guess now.
 *
 * A failure here does not make the run wrong -- the directory is this
 * process's own and lives under the system temp path, and nothing in the
 * repository was touched either way -- so it does not change the exit status.
 * But it is not discarded either: AGENTS.md forbids `@` and silently swallowed
 * errors, and a CI box quietly accumulating stale farms until the disk fills
 * is exactly the sort of thing that is discovered far from its cause. The
 * diagnostic goes to stderr, which the test folds into its failure message.
 */
register_shutdown_function(static function () use ($farm): void {
    $remaining = scandir($farm);
    if ($remaining === false) {
        fwrite(STDERR, "could not read $farm to clear it away\n");

        return;
    }

    $stuck = [];
    foreach ($remaining as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!unlink($farm . '/' . $entry)) {
            $stuck[] = $entry;
        }
    }

    if ($stuck !== []) {
        fwrite(STDERR, sprintf("could not clear %s from %s\n", implode(', ', $stuck), $farm));

        return;
    }

    if (!rmdir($farm)) {
        fwrite(STDERR, "could not remove $farm\n");
    }
});

$entries = scandir($root);
if ($entries === false) {
    fwrite(STDERR, "could not read $root\n");
    exit(SETUP_FAILED);
}

foreach ($entries as $entry) {
    if (in_array($entry, ['.', '..', 'common.php', 'cron.php'], true)) {
        continue;
    }
    if (!symlink($root . '/' . $entry, $farm . '/' . $entry)) {
        fwrite(STDERR, "could not link $entry into $farm\n");
        exit(SETUP_FAILED);
    }
}

if (
    !copy(__DIR__ . '/fixtures/cron_exception/common.php', $farm . '/common.php')
    || !copy($root . '/cron.php', $farm . '/cron.php')
) {
    fwrite(STDERR, "could not populate $farm\n");
    exit(SETUP_FAILED);
}

$mail_sent_count = 0;
new PHPMailer();

$output = new class {
    public function appoencode($data, $priv)
    {
        return $data;
    }
};


require $farm . '/cron.php';
