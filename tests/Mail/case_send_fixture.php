<?php

declare(strict_types=1);

namespace Lotgd\Tests\Mail\Fixture;

$source = file_get_contents(dirname(__DIR__, 2) . '/pages/mail/case_send.php');

if ($source === false) {
    throw new \RuntimeException('Unable to load case_send.php for mail fixture.');
}

$source = preg_replace('/^\s*<\?php/', '', $source, 1);
$source = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/', '', $source, 1);
$source = preg_replace('/mailSend\s*\(\s*\)\s*;\s*$/', '', $source, 1);

eval('namespace ' . __NAMESPACE__ . ' {' . $source . '}');
