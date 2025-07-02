<?php

/**
 * File.php - Unable to read config file.
 *
 * This exception is thrown when the config file cannot be read.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Config\Exception;

class FileContent extends \Exception
{
    /**
     * @var string
     */
    public $sConfigFile;

    /**
     * @param string $sConfigFile
     */
    public function __construct(string $sConfigFile)
    {
        parent::__construct();
        $this->sConfigFile = $sConfigFile;
    }
}
