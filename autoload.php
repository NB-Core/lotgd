<?php

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    throw new RuntimeException("The vendor autoload file was not found at: {$autoloadPath}");
}
$loader = require $autoloadPath;
$loader->addPsr4('Lotgd\\', __DIR__ . '/src/Lotgd/');
$loader->addPsr4('Lotgd\\Installer\\', __DIR__ . '/install/lib/');
$loader->addPsr4('Symfony\\Component\\Cache\\', __DIR__ . '/vendor/symfony/cache/');
$loader->addPsr4('Symfony\\Contracts\\Cache\\', __DIR__ . '/vendor/symfony/cache-contracts/');
