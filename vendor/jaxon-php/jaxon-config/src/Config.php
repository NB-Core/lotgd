<?php

/**
 * Config.php
 *
 * An immutable class for config options.
 *
 * @package jaxon-config
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2025 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Config;

use Jaxon\Config\Reader\Value;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function trim;

class Config
{
    /**
     * The constructor
     *
     * @param array $aValues
     * @param bool $bChanged
     */
    public function __construct(private array $aValues = [], private bool $bChanged = true)
    {}

    /**
     * Get the config values
     *
     * @return array
     */
    public function getValues(): array
    {
        return $this->aValues;
    }

    /**
     * If the values has changed
     *
     * @return bool
     */
    public function changed(): bool
    {
        return $this->bChanged;
    }

    /**
     * Get the value of a config option
     *
     * @param string $sName The option name
     * @param mixed $xDefault The default value, to be returned if the option is not defined
     *
     * @return mixed
     */
    public function getOption(string $sName, $xDefault = null)
    {
        return $this->aValues[$sName] ?? $xDefault;
    }

    /**
     * Check the presence of a config option
     *
     * @param string $sName The option name
     *
     * @return bool
     */
    public function hasOption(string $sName): bool
    {
        return array_key_exists($sName, $this->aValues);
    }

    /**
     * Get the names of the options under a given key
     *
     * @param string $sKey The prefix to match
     *
     * @return array
     */
    public function getOptionNames(string $sKey): array
    {
        $sKey = trim($sKey, ' .');
        $aKeys = Value::explodeName($sKey);
        $aValues = $this->aValues;
        foreach($aKeys as $_sKey)
        {
            $aValues = $aValues[$_sKey] ?? [];
        }
        if(!Value::containsOptions($aValues))
        {
            return [];
        }

        // The returned value is an array with short names as keys and full names as values.
        $aNames = array_keys($aValues);
        $aFullNames = array_map(fn($sName) => "$sKey.$sName", $aNames);
        return array_combine($aNames, $aFullNames);
    }
}
