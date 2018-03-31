<?php

/**
 * start.php -
 *
 * This file is automatically loaded by the Composer autoloader
 *
 * The Armada instance is registered in the DI container here.
 *
 * @package jaxon-armada
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2018 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-armada
 */

/*
 * Register the Armada instance in the DI container
 */
\Jaxon\Utils\Container::getInstance()->setArmada(function ($c) {
    return new \Jaxon\Armada\Armada();
});
