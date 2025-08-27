<?php

declare(strict_types=1);

namespace Lotgd\Tests\Cron;

require __DIR__ . '/../autoload.php';
require_once __DIR__ . '/Stubs/Functions.php';

use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;

$testFile = $argv[1] ?? null;

global $settings, $GAME_DIR, $argv, $mail_sent_count, $output;

$settings = new DummySettings([
    'notify_on_error' => 1,
    'notify_address'  => 'admin@example.com',
    'gameadminemail'  => 'admin@example.com',
]);

$GAME_DIR = '/PATH/TO/GAME';
$argv = [];

if (!is_dir($GAME_DIR)) {
    mkdir($GAME_DIR, 0777, true);
}
copy(__DIR__ . '/fixtures/cron_exception/common.php', $GAME_DIR . '/common.php');

$mail_sent_count = 0;
new PHPMailer();

$output = new class {
    public function appoencode($data, $priv)
    {
        return $data;
    }
};

register_shutdown_function(function () use ($testFile): void {
    if ($testFile) {
        file_put_contents($testFile, (string) ($GLOBALS['mail_sent_count'] ?? ''));
    }
});

require __DIR__ . '/../cron.php';

