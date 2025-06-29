<?php

/**
 * Translator.php - Translator
 *
 * Provide translation service for strings in the Jaxon library.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Translation;

use function trim;
use function file_exists;
use function is_array;
use function str_replace;
use function array_map;
use function array_keys;
use function array_values;

class Translator
{
    /**
     * The default locale
     *
     * @var string
     */
    protected $sDefaultLocale = 'en';

    /**
     * The translations
     *
     * @var array
     */
    protected $aTranslations = [];

    /**
     * Set the default locale
     *
     * @param string $sLocale
     *
     * @return void
     */
    public function setLocale(string $sLocale)
    {
        if(($sLocale = trim($sLocale)))
        {
            $this->sDefaultLocale = $sLocale;
        }
    }

    /**
     * Recursively load translated strings from an array
     *
     * @param string $sLanguage The language of the translations
     * @param string $sPrefix The prefix for names
     * @param array $aTranslations The translated strings
     *
     * @return void
     */
    private function _loadTranslations(string $sLanguage, string $sPrefix, array $aTranslations)
    {
        foreach($aTranslations as $sKey => $xTranslation)
        {
            $sKey = trim($sKey);
            $sKey = ($sPrefix) ? $sPrefix . '.' . $sKey : $sKey;
            if(is_array($xTranslation))
            {
                // Recursively read the translations in the array
                $this->_loadTranslations($sLanguage, $sKey, $xTranslation);
            }
            else
            {
                // Save this translation
                $this->aTranslations[$sLanguage][$sKey] = $xTranslation;
            }
        }
    }

    /**
     * Load translated strings from a file
     *
     * @param string $sFilePath The file full path
     * @param string $sLanguage The language of the strings in this file
     *
     * @return bool
     */
    public function loadTranslations(string $sFilePath, string $sLanguage): bool
    {
        if(!file_exists($sFilePath))
        {
            return false;
        }
        $aTranslations = require($sFilePath);
        if(!is_array($aTranslations))
        {
            return false;
        }
        // Load the translations
        if(!isset($this->aTranslations[$sLanguage]))
        {
            $this->aTranslations[$sLanguage] = [];
        }
        $this->_loadTranslations($sLanguage, '', $aTranslations);
        return true;
    }

    /**
     * Get a translated string
     *
     * @param string $sText The key of the translated string
     * @param array $aPlaceHolders The placeholders of the translated string
     * @param string $sLanguage The language of the translated string
     *
     * @return string
     */
    public function trans(string $sText, array $aPlaceHolders = [], string $sLanguage = ''): string
    {
        $sText = trim($sText);
        if(empty($sLanguage))
        {
            $sLanguage = $this->sDefaultLocale;
        }
        if(!isset($this->aTranslations[$sLanguage][$sText]))
        {
            return $sText;
        }
        $sMessage = $this->aTranslations[$sLanguage][$sText];
        if(!empty($aPlaceHolders))
        {
            $aVars = array_map(function($sVar) {
                return ':' . $sVar;
            }, array_keys($aPlaceHolders));
            $sMessage = str_replace($aVars, array_values($aPlaceHolders), $sMessage);
        }
        return $sMessage;
    }
}
