<?php

declare(strict_types=1);

use Lotgd\QA\SqlAddslashesUsageCheck;

require dirname(__DIR__) . '/vendor/autoload.php';

$checker = new SqlAddslashesUsageCheck();
exit($checker->run(dirname(__DIR__)));
