<?php

/**
 * Renderer.php - Paginator renderer
 *
 * Render pagination links.
 *
 * @package jaxon-sentry
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2016 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-sentry
 */

namespace Jaxon\Sentry\Pagination;

class Renderer extends \Jaxon\Utils\Pagination\Renderer
{
    /**
     * Render a pagination template
     *
     * @param string        $sTemplate            The name of template to be rendered
     * @param string        $aVars                The template vars
     *
     * @return string        The template content
     */
    protected function _render($sTemplate, array $aVars = array())
    {
        return jaxon()->sentry()->getViewRenderer()->render($sTemplate, $aVars);
    }
}
