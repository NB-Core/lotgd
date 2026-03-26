<?php

declare(strict_types=1);

use Lotgd\QA\LegacyHttpWrapperUsageCheck;

require dirname(__DIR__) . '/vendor/autoload.php';

$checker = new LegacyHttpWrapperUsageCheck();
exit($checker->run(dirname(__DIR__)));

