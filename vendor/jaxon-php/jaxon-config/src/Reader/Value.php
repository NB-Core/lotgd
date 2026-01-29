<?php

/**
 * Oprion.php
 *
 * Util functions for options values.
 *
 * @package jaxon-config
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2025 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Config\Reader;

use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function is_array;
use function is_string;
use function trim;

class Value
{
    /**
     * Check if a value is an array of options
     *
     * @param mixed $xValue
     *
     * @return bool
     */
    public static function containsOptions($xValue): bool
    {
        if(!is_array($xValue) || count($xValue) === 0)
        {
            return false;
        }
        foreach(array_keys($xValue) as $xKey)
        {
            if(!is_string($xKey))
            {
                return false;
            }
        }
        return true;
    }

    /**
     * Get an array of options names
     *
     * @param string $sOptionName
     *
     * @return array
     */
    public static function explodeName(string $sOptionName): array
    {
        $aNames = explode('.', $sOptionName);
        $aNames = array_map(fn($sName) => trim($sName), $aNames);
        return array_filter($aNames, fn($sName) => $sName !== '');
    }
}
