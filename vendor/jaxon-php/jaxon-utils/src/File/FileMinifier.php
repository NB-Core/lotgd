<?php

/**
 * FileMinifier.php
 *
 * Minify javascript or css code.
 *
 * @package jaxon-utils
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\File;

use JShrink\Minifier as JsMinifier;
use MatthiasMullie\Minify\CSS as CssMinifier;
use Exception;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function trim;

class FileMinifier
{
    /**
     * @var CssMinifier
     */
    private $xCssMinifier = null;

    /**
     * @return CssMinifier
     */
    private function css(): CssMinifier
    {
        return $this->xCssMinifier ?? $this->xCssMinifier = new CssMinifier();
    }

    /**
     * Minify javascript or css code
     *
     * @param string $sCode The javascript or css code to be minified
     *
     * @return string|false
     */
    public function minifyJsCode(string $sCode): string|false
    {
        try
        {
            $sMinCode = trim(JsMinifier::minify($sCode));
            return $sMinCode === '' ? false : $sMinCode;
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * Minify javascript or css code
     *
     * @param string $sCode The javascript or css code to be minified
     *
     * @return string|false
     */
    public function minifyCssCode(string $sCode): string|false
    {
        try
        {
            $sMinCode = trim($this->css()->add($sCode)->minify());
            return $sMinCode === '' ? false : $sMinCode;
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * Minify javascript file
     *
     * @param string $sFile The javascript file to be minified
     * @param string $sMinFile The minified javascript file
     *
     * @return bool
     */
    public function minifyJsFile(string $sFile, string $sMinFile): bool
    {
        try
        {
            $sCode = file_get_contents($sFile);
            $sMinCode = trim(JsMinifier::minify($sCode));
            if($sMinCode === '')
            {
                return false;
            }

            file_put_contents($sMinFile, $sMinCode);
            return is_file($sMinFile);
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * Minify css file
     *
     * @param string $sFile The css file to be minified
     * @param string $sMinFile The minified css file
     *
     * @return bool
     */
    public function minifyCssFile(string $sFile, string $sMinFile): bool
    {
        try
        {
            $sCode = file_get_contents($sFile);
            $sMinCode = trim($this->css()->add($sCode)->minify());
            if($sMinCode === '')
            {
                return false;
            }

            file_put_contents($sMinFile, $sMinCode);
            return is_file($sMinFile);
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * Minify javascript file
     *
     * @param string $sFile The javascript file to be minified
     * @param string $sMinFile The minified javascript file
     *
     * @return bool
     */
    public function minify(string $sFile, string $sMinFile): bool
    {
        return $this->minifyJsFile($sFile, $sMinFile);
    }
}
