<?php

/**
 * Project autoloader plus the test namespaces.
 *
 * The test namespaces are registered here rather than as autoload-dev in
 * composer.json: the committed vendor/ holds production code only, and an
 * autoload-dev entry would make every dev-mode `composer install` rewrite its
 * generated autoload files. Scripts the tests start in a subprocess require
 * this file instead of the root autoload.php whenever they use a
 * Lotgd\Tests class.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

(static function (): void {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Lotgd\\Tests\\', __DIR__ . '/');
    $loader->addPsr4('Lotgd\\Tests\\Stubs\\', __DIR__ . '/Stubs/');
    $loader->register();
})();
