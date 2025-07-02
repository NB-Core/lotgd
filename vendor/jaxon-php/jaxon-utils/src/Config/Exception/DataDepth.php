<?php

/**
 * DataDepth.php - Incorrect config data exception
 *
 * This exception is thrown when config data are incorrect.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Config\Exception;

class DataDepth extends \Exception
{
    /**
     * @var string
     */
    public $sPrefix;

    /**
     * @var int
     */
    public $nDepth;

    /**
     * @param string $sPrefix
     * @param int $nDepth
     */
    public function __construct(string $sPrefix, int $nDepth)
    {
        parent::__construct();
        $this->sPrefix = $sPrefix;
        $this->nDepth = $nDepth;
    }
}
