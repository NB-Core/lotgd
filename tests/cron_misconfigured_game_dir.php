<?php

declare(strict_types=1);

namespace Lotgd\Tests\Cron;

use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;

global $settings, $GAME_DIR, $argv, $mail_sent_count;

$settings = new DummySettings([
    'gameadminemail' => 'admin@example.com',
    'serverurl' => 'http://example.com',
]);

$GAME_DIR = '';
$argv = [];

$mail_sent_count = 0;
new PHPMailer();

define('CRON_TEST', true);

require __DIR__ . '/../cron.php';
$sent = $mail_sent_count;
unset($settings, $GAME_DIR, $argv, $mail_sent_count);

return $sent;
