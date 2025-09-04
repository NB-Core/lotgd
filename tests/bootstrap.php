<?php

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../src/Lotgd/Config/constants.php';

require_once __DIR__ . '/Stubs/DbMysqli.php';
require_once __DIR__ . '/Stubs/Database.php';
require_once __DIR__ . '/Stubs/ArrayCache.php';
require_once __DIR__ . '/Stubs/Functions.php';
// Preload repository classes to avoid redeclaration when Doctrine loads them
require_once realpath(__DIR__ . '/../src/Lotgd/Repository/AccountRepository.php');
require_once realpath(__DIR__ . '/../src/Lotgd/Repository/SettingRepository.php');
require_once realpath(__DIR__ . '/../src/Lotgd/Repository/ExtendedSettingRepository.php');
class_exists(\Lotgd\DataCache::class);
if (! class_exists('Lotgd\\Datacache', false)) {
    class_alias(\Lotgd\DataCache::class, 'Lotgd\\Datacache');
}
