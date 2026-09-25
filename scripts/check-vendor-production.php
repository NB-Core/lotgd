<?php

declare(strict_types=1);

use Lotgd\QA\VendorProductionCheck;

// Loaded directly rather than through vendor/autoload.php: this script is
// what reports a missing or broken vendor/, so it must not need a working
// one to start. The class uses nothing but PHP itself.
require dirname(__DIR__) . '/src/Lotgd/QA/VendorProductionCheck.php';

$checker = new VendorProductionCheck();
exit($checker->run(dirname(__DIR__)));
