<?php

/**
 * start.php -
 *
 * This file is automatically loaded by the Composer autoloader
 *
 * The Sentry instance is registered in the DI container here.
 *
 * @package jaxon-sentry
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2018 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-sentry
 */

/*
 * Register the Sentry instance in the DI container
 */
\Jaxon\Utils\Container::getInstance()->setSentry(function ($c) {
    return new \Jaxon\Sentry\Sentry();
});
