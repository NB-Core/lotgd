<?php

declare(strict_types=1);

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;

require __DIR__ . '/bootstrap.php';

global $settings, $mail_sent_count, $last_subject, $output;

$mail_sent_count = 0;
$last_subject = '';
$settings = new DummySettings([
    'notify_address' => 'admin@example.com',
    'gameadminemail' => 'admin@example.com',
    'usedatacache' => 0,
]);

new PHPMailer();

$output = new class {
    public function appoencode($data, $priv)
    {
        return $data;
    }
};

$_SERVER['HTTP_HOST'] = 'example.com';

ob_start();
register_shutdown_function(function (): void {
    ob_end_clean();
    echo 'mail_sent_count=' . ($GLOBALS['mail_sent_count'] ?? 0);
});

ErrorHandler::handleError(E_ERROR, 'Test error', 'file.php', 42);
