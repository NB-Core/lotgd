<?php

declare(strict_types=1);

use Lotgd\QA\VendorProductionCheck;

require dirname(__DIR__) . '/vendor/autoload.php';

$checker = new VendorProductionCheck();
exit($checker->run(dirname(__DIR__)));
