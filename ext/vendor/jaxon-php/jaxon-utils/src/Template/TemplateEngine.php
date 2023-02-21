<?php

/**
 * TemplateEngine.php - Template engine
 *
 * Generate templates with template vars.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Template;

use function trim;
use function rtrim;
use function substr;
use function strrpos;
use function ob_start;
use function ob_get_clean;
use function call_user_func;

class TemplateEngine
{
    /**
     * The namespaces
     *
     * @var array
     */
    protected $aNamespaces;

    /**
     * The default namespace
     *
     * @var string
     */
    protected $sDefaultNamespace = '';

    /**
     * Set the default namespace
     *
     * @param string $sDefaultNamespace
     */
    public function setDefaultNamespace(string $sDefaultNamespace): void
    {
        $this->sDefaultNamespace = $sDefaultNamespace;
    }

    /**
     * Add a namespace to the template system
     *
     * @param string $sNamespace The namespace name
     * @param string $sDirectory The namespace directory
     * @param string $sExtension The extension to append to template names
     *
     * @return void
     */
    public function addNamespace(string $sNamespace, string $sDirectory, string $sExtension = '')
    {
        $this->aNamespaces[$sNamespace] = [
            'directory' => rtrim(trim($sDirectory), "/\\") . DIRECTORY_SEPARATOR,
            'extension' => $sExtension,
        ];
    }

    /**
     * Render a template
     *
     * @param string $sPath The path to the template
     * @param array $aVars The template vars
     *
     * @return string
     */
    private function renderTemplate(string $sPath, array $aVars): string
    {
        if(!is_readable($sPath))
        {
            return '';
        }
        // Make the template vars available in a Context object.
        $xContext = new Context($this, $aVars);
        // Render the template
        $cRenderer = function() use($sPath) {
            ob_start();
            include($sPath);
            return ob_get_clean();
        };
        // Call the closure in the context of the Context object.
        // So the keyword '$this' in the template will refer to the $xContext object.
        return call_user_func($cRenderer->bindTo($xContext));
    }

    /**
     * Render a template
     *
     * @param string $sTemplate The name of template to be rendered
     * @param array $aVars The template vars
     *
     * @return string
     */
    public function render(string $sTemplate, array $aVars = []): string
    {
        $sTemplate = trim($sTemplate);
        // Get the namespace name
        $sNamespace = $this->sDefaultNamespace;
        $nSeparatorPosition = strrpos($sTemplate, '::');
        if($nSeparatorPosition !== false)
        {
            $sNamespace = substr($sTemplate, 0, $nSeparatorPosition);
            $sTemplate = substr($sTemplate, $nSeparatorPosition + 2);
        }
        // Check if the namespace is defined
        if(!isset($this->aNamespaces[$sNamespace]))
        {
            return '';
        }
        $aNamespace = $this->aNamespaces[$sNamespace];
        // Get the template path
        $sTemplatePath = $aNamespace['directory'] . $sTemplate . $aNamespace['extension'];
        // Render the template
        return $this->renderTemplate($sTemplatePath, $aVars);
    }
}
