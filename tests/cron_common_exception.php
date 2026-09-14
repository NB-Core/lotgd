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
$root = dirname(__DIR__);
$farm = sys_get_temp_dir() . '/lotgd-cron-exception-' . getmypid() . '-' . bin2hex(random_bytes(4));

if (!mkdir($farm, 0777, true) && !is_dir($farm)) {
    fwrite(STDERR, "could not create $farm\n");
    exit(1);
}

foreach ((array) scandir($root) as $entry) {
    if (in_array($entry, ['.', '..', 'common.php', 'cron.php'], true)) {
        continue;
    }
    if (!symlink($root . '/' . $entry, $farm . '/' . $entry)) {
        fwrite(STDERR, "could not link $entry into $farm\n");
        exit(1);
    }
}

if (
    !copy(__DIR__ . '/fixtures/cron_exception/common.php', $farm . '/common.php')
    || !copy($root . '/cron.php', $farm . '/cron.php')
) {
    fwrite(STDERR, "could not populate $farm\n");
    exit(1);
}

$mail_sent_count = 0;
new PHPMailer();

$output = new class {
    public function appoencode($data, $priv)
    {
        return $data;
    }
};

// Best effort, and harmless if it fails: the directory is this process's own
// and lives under the system temp path. Nothing in the repository is touched,
// so a crash here costs a stale temp directory rather than a missing file in
// somebody's checkout.
register_shutdown_function(static function () use ($farm): void {
    foreach ((array) @scandir($farm) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($farm . '/' . $entry);
    }
    @rmdir($farm);
});

require $farm . '/cron.php';
