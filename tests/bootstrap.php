<?php

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/constants.php';

foreach (glob(__DIR__ . '/Stubs/*.php') as $stubFile) {
    require_once $stubFile;
}
