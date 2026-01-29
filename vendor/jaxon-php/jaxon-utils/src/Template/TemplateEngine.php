<?php

/**
 * TemplateEngine.php - Template engine
 *
 * Generate templates with template vars.
 *
 * @package jaxon-utils
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Template;

use function trim;
use function rtrim;

class TemplateEngine
{
    /**
     * The default namespace
     *
     * @var string
     */
    protected $sDefaultNamespace = '';

    /**
     * The namespaces
     *
     * @var array
     */
    protected $aNamespaces;

    /**
     * Set the default namespace
     *
     * @param string $sDefaultNamespace
     *
     * @return void
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
     * @return static
     */
    public function addNamespace(string $sNamespace, string $sDirectory, string $sExtension = ''): static
    {
        $this->aNamespaces[$sNamespace] = [
            'directory' => rtrim(trim($sDirectory), "/\\") . DIRECTORY_SEPARATOR,
            'extension' => $sExtension,
        ];
        return $this;
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
        $context = new Context($this->aNamespaces, $this->sDefaultNamespace, $sTemplate);
        return $context->__render($aVars);
    }
}
