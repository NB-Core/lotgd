<?php

declare(strict_types=1);

define('DB_NODB', true);

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../src/Lotgd/Config/constants.php';

use Lotgd\ErrorHandler;

try {
    strlen([]);
} catch (\TypeError $e) {
    ob_start();
    ErrorHandler::handleException($e);
    ob_end_clean();
}

echo 'done';
