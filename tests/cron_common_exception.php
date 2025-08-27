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

$testFile = $argv[1] ?? null;

global $settings, $GAME_DIR, $argv, $mail_sent_count, $output;

$cacheDir = sys_get_temp_dir();
$settings = new DummySettings([
    'notify_on_error' => 1,
    'notify_address'  => 'admin@example.com',
    'gameadminemail'  => 'admin@example.com',
    'usedatacache'    => 1,
    'datacachepath'   => $cacheDir,
]);

$GAME_DIR = sys_get_temp_dir() . '/cron-test-' . uniqid();
$argv     = [];

if (!is_dir($GAME_DIR)) {
    mkdir($GAME_DIR, 0777, true);
}
copy(__DIR__ . '/fixtures/cron_exception/common.php', $GAME_DIR . '/common.php');

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$settingsFile     = __DIR__ . '/../settings.php';
$originalSettings = file_get_contents($settingsFile);
$updatedSettings  = str_replace(
    "\$GAME_DIR = '/PATH/TO/GAME';",
    "\$GAME_DIR = '{$GAME_DIR}';",
    $originalSettings
);
file_put_contents($settingsFile, $updatedSettings);

$mail_sent_count = 0;
new PHPMailer();

$output = new class {
    public function appoencode($data, $priv)
    {
        return $data;
    }
};

register_shutdown_function(function () use ($testFile, $GAME_DIR, $settingsFile, $originalSettings): void {
    if ($testFile) {
        file_put_contents($testFile, (string) ($GLOBALS['mail_sent_count'] ?? ''));
    }

    file_put_contents($settingsFile, $originalSettings);

    $file = $GAME_DIR . '/common.php';
    if (is_file($file)) {
        unlink($file);
    }
    if (is_dir($GAME_DIR)) {
        rmdir($GAME_DIR);
    }
});

require __DIR__ . '/../cron.php';

