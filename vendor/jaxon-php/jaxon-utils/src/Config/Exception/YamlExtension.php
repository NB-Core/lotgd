<?php

/**
 * YamlExtension.php - YamlExtension-specific exception.
 *
 * This exception is thrown when an error related to YamlExtension occurs.
 * A typical example is when the php-yaml package is not installed.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Config\Exception;

class YamlExtension extends \Exception
{
}
