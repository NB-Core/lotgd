<?php
$autoloadPath = __DIR__.'/ext/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    throw new RuntimeException("The vendor autoload file was not found at: {$autoloadPath}");
}
$loader = require $autoloadPath;
$loader->addPsr4('Lotgd\\', __DIR__.'/src/Lotgd/');

