<?php

/**
 * ConfigReader.php
 *
 * Read config values from files.
 *
 * @package jaxon-config
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Config;

use function trim;
use function pathinfo;

class ConfigReader
{
    /**
     * @param ConfigSetter $xConfigSetter
     */
    public function __construct(private ConfigSetter $xConfigSetter)
    {}

    /**
     * Read options from a config file to an array
     *
     * @param string $sConfigFile The full path to the config file
     *
     * @return array
     * @throws Exception\FileAccess
     * @throws Exception\FileExtension
     * @throws Exception\FileContent
     * @throws Exception\YamlExtension
     */
    public function read(string $sConfigFile): array
    {
        if(!($sConfigFile = trim($sConfigFile)))
        {
            return [];
        }

        $sExt = pathinfo($sConfigFile, PATHINFO_EXTENSION);
        switch($sExt)
        {
        case 'php':
            $aConfigOptions = Reader\PhpReader::read($sConfigFile);
            break;
        case 'yaml':
        case 'yml':
            $aConfigOptions = Reader\YamlReader::read($sConfigFile);
            break;
        case 'json':
            $aConfigOptions = Reader\JsonReader::read($sConfigFile);
            break;
        default:
            throw new Exception\FileExtension($sConfigFile);
        }

        return $aConfigOptions;
    }

    /**
     * Read options from a config file to a config object
     *
     * @param Config $xConfig
     * @param string $sConfigFile The full path to the config file
     * @param string $sConfigSection The section of the config file to be loaded
     *
     * @return Config
     * @throws Exception\DataDepth
     * @throws Exception\FileAccess
     * @throws Exception\FileExtension
     * @throws Exception\FileContent
     * @throws Exception\YamlExtension
     */
    public function load(Config $xConfig, string $sConfigFile, string $sConfigSection = ''): Config
    {
        // Read the options and save in the config.
        return $this->xConfigSetter
            ->setOptions($xConfig, $this->read($sConfigFile), '', $sConfigSection);
    }
}
