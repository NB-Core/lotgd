<?php

/**
 * Context.php - Template context
 *
 * A context for a template being rendered.
 *
 * The "$this" var in a template will refer to an instance of this
 * class, which will then provide the template variables, and the
 * render() method, to render a template inside of another.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Template;

class Context
{
    /**
     * @var TemplateEngine
     */
    private $__engine__;

    /**
     * @var array
     */
    private $__properties__ = [];

    /**
     * The constructor
     *
     * @param TemplateEngine $engine
     * @param array $aVars The template vars
     */
    public function __construct(TemplateEngine $engine, array $aVars)
    {
        $this->__engine__ = $engine;
        foreach($aVars as $sName => $xValue)
        {
            $this->__set((string)$sName, $xValue);
        }
    }

    /**
     * @param string $sName
     *
     * @return mixed
     */
    public function __get(string $sName)
    {
        return $this->__properties__[$sName];
    }

    /**
     * @param string $sName
     * @param mixed $xValue
     *
     * @return void
     */
    public function __set(string $sName, $xValue)
    {
        $this->__properties__[$sName] = $xValue;
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
        return $this->__engine__->render($sTemplate, $aVars);
    }

    /**
     * Include a template
     *
     * @param string $sTemplate The name of template to be rendered
     * @param array $aVars The template vars
     *
     * @return void
     */
    public function include(string $sTemplate, array $aVars = [])
    {
        echo $this->render($sTemplate, $aVars);
    }
}
