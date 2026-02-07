<?php

/**
 * ConfigSetter.php
 *
 * Set values in immutable config objects.
 *
 * @package jaxon-config
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2025 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Config;

use Jaxon\Config\Exception\DataDepth;
use Jaxon\Config\Reader\Value;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_pop;
use function count;
use function implode;
use function is_array;
use function rtrim;
use function strlen;
use function substr;
use function trim;

class ConfigSetter
{
    /**
     * Create a new config object
     *
     * @param array $aOptions The options values to be set
     * @param string $sNamePrefix A prefix for the config option names
     * @param string $sValuePrefix A prefix of the values in the input array
     *
     * @return Config
     * @throws DataDepth
     */
    public function newConfig(array $aOptions = [],
        string $sNamePrefix = '', string $sValuePrefix = ''): Config
    {
        return count($aOptions) === 0 ? new Config() :
            $this->setOptions(new Config(), $aOptions, $sNamePrefix, $sValuePrefix);
    }

    /**
     * Get the last entry from and array and return its length
     *
     * @param string $sLastName
     * @param array $aNames
     *
     * @return int
     */
    private function pop(string &$sLastName, array &$aNames): int
    {
        $sLastName = array_pop($aNames);
        return count($aNames);
    }

    /**
     * Set the value of a config option
     *
     * @param array $aValues The current options values
     * @param string $sOptionName The option name
     * @param mixed $xOptionValue The option value
     *
     * @return array
     */
    private function setValue(array $aValues, string $sOptionName, $xOptionValue): array
    {
        // Given an option name like a.b.c, the values of a and a.b must also be set.
        $xValue = $xOptionValue;
        $sLastName = '';
        $aNames = Value::explodeName($sOptionName);
        while($this->pop($sLastName, $aNames) > 0)
        {
            $sName = implode('.', $aNames);
            // The current value is overwritten if it is not an array of options.
            $xCurrentValue = isset($aValues[$sName]) &&
                Value::containsOptions($aValues[$sName]) ? $aValues[$sName] : [];
            $aValues[$sName] = array_merge($xCurrentValue, [$sLastName => $xValue]);
            $xValue = $aValues[$sName];
        }

        // Set the input option value.
        $aValues[$sOptionName] = $xOptionValue;
        return $aValues;
    }

    /**
     * Set the value of a config option
     *
     * @param Config $xConfig
     * @param string $sName The option name
     * @param mixed $xValue The option value
     *
     * @return Config
     */
    public function setOption(Config $xConfig, string $sName, $xValue): Config
    {
        return new Config($this->setValue($xConfig->getValues(), $sName, $xValue));
    }

    /**
     * Recursively set options from a data array
     *
     * @param array $aValues The current options values
     * @param array $aOptions The options values to be set
     * @param string $sNamePrefix The prefix for option names
     * @param int $nDepth The depth from the first call
     *
     * @return array
     * @throws DataDepth
     */
    private function setValues(array $aValues, array $aOptions,
        string $sNamePrefix = '', int $nDepth = 0): array
    {
        // Check the max depth
        if($nDepth < 0 || $nDepth > 9)
        {
            throw new DataDepth($sNamePrefix, $nDepth);
        }

        foreach($aOptions as $sName => $xValue)
        {
            $sName = trim($sName);
            if(!Value::containsOptions($xValue))
            {
                // Save the value of this option
                $aValues = $this->setValue($aValues, $sNamePrefix . $sName, $xValue);
                continue;
            }

            // Recursively set the options in the array. Important to set a new var.
            $sNextPrefix = $sNamePrefix . $sName . '.';
            $aValues = $this->setValues($aValues, $xValue, $sNextPrefix, $nDepth + 1);
        }
        return $aValues;
    }

    /**
     * Set the values of an array of config options
     *
     * @param Config $xConfig
     * @param array $aOptions The options values to be set
     * @param string $sNamePrefix A prefix for the config option names
     * @param string $sValuePrefix A prefix of the values in the input array
     *
     * @return Config
     * @throws DataDepth
     */
    public function setOptions(Config $xConfig, array $aOptions,
        string $sNamePrefix = '', string $sValuePrefix = ''): Config
    {
        // Find the config array in the input data
        $sValuePrefix = trim($sValuePrefix, ' .');
        $aKeys = Value::explodeName($sValuePrefix);
        foreach($aKeys as $sKey)
        {
            if(($sKey))
            {
                if(!isset($aOptions[$sKey]) || !is_array($aOptions[$sKey]))
                {
                    // No change if the required key is not found.
                    return new Config($xConfig->getValues(), false);
                }

                $aOptions = $aOptions[$sKey];
            }
        }

        $sNamePrefix = trim($sNamePrefix, ' .');
        if(($sNamePrefix))
        {
            $sNamePrefix .= '.';
        }
        return new Config($this->setValues($xConfig->getValues(), $aOptions, $sNamePrefix));
    }

    /**
     * Unset a config option
     *
     * @param Config $xConfig
     * @param string $sName The option name
     *
     * @return Config
     */
    public function unsetOption(Config $xConfig, string $sName): Config
    {
        return $this->unsetOptions($xConfig, [$sName]);
    }

    /**
     * @param array $aValues The option values
     * @param string $sName The option name
     *
     * @return array
     */
    private function deleteEntries(array $aValues, string $sName): array
    {
        $sName = rtrim($sName, '.');
        if(!array_key_exists($sName, $aValues))
        {
            return $aValues;
        }

        // Delete the entry, and all the matching entries.
        $sPrefix = $sName . '.';
        $nPrefixLength = strlen($sPrefix);
        $cFilter = fn($sOptionName) => $sOptionName !== $sName &&
            substr($sOptionName, 0, $nPrefixLength) !== $sPrefix;
        return array_filter($aValues, $cFilter, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Unset an array of config options
     *
     * @param Config $xConfig
     * @param array<string> $aNames The option names
     *
     * @return Config
     */
    public function unsetOptions(Config $xConfig, array $aNames): Config
    {
        $aValues = $xConfig->getValues();
        $nEntryCount = count($aValues);
        foreach($aNames as $sName)
        {
            $aValues = $this->deleteEntries($aValues, $sName);
        }
        // The number of entries is the same if no entry was deleted.
        return new Config($aValues, $nEntryCount !== count($aValues));
    }
}
