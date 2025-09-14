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

$commonFile = __DIR__ . '/../common.php';
$backupFile = $commonFile . '.bak';
rename($commonFile, $backupFile);
copy(__DIR__ . '/fixtures/cron_exception/common.php', $commonFile);

$mail_sent_count = 0;
new PHPMailer();

$output = new class {
    public function appoencode($data, $priv)
    {
        return $data;
    }
};

register_shutdown_function(function () use ($commonFile, $backupFile): void {
    if (is_file($commonFile)) {
        unlink($commonFile);
    }
    if (is_file($backupFile)) {
        rename($backupFile, $commonFile);
    }
});

require __DIR__ . '/../cron.php';
