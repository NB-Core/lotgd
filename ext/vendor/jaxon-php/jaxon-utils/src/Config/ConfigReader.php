<?php

/**
 * ConfigReader.php - Jaxon config reader
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Config;

use function trim;
use function pathinfo;

class ConfigReader
{
    /**
     * Read options from a config file
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
            $aConfigOptions = File\Php::read($sConfigFile);
            break;
        case 'yaml':
        case 'yml':
            $aConfigOptions = File\Yaml::read($sConfigFile);
            break;
        case 'json':
            $aConfigOptions = File\Json::read($sConfigFile);
            break;
        default:
            throw new Exception\FileExtension($sConfigFile);
        }

        return $aConfigOptions;
    }

    /**
     * Read options from a config file and setup the library
     *
     * @param Config $xConfig
     * @param string $sConfigFile The full path to the config file
     * @param string $sConfigSection The section of the config file to be loaded
     *
     * @return void
     * @throws Exception\DataDepth
     * @throws Exception\FileAccess
     * @throws Exception\FileExtension
     * @throws Exception\FileContent
     * @throws Exception\YamlExtension
     */
    public function load(Config $xConfig, string $sConfigFile, string $sConfigSection = '')
    {
        // Read the options and save in the config.
        $xConfig->setOptions($this->read($sConfigFile), $sConfigSection);
    }
}
