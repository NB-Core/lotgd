<?php

/**
 * Context.php - Template context
 *
 * A context for a template being rendered.
 *
 * The "$this" var in a template will refer to an instance of this
 * class, which will then provide the template variables, and the
 * include() method, to render a template inside of another.
 *
 * @package jaxon-utils
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Template;

use Closure;

use function call_user_func;
use function is_readable;
use function ob_get_clean;
use function ob_start;
use function strrpos;
use function substr;
use function trim;

class Context
{
    /**
     * @var Context|null
     */
    private $__extends__ = null;

    /**
     * @var string
     */
    private $__block_name__;

    /**
     * @var array
     */
    private $__properties__ = [];

    /**
     * The constructor
     *
     * @param array $__namespaces__
     * @param string $__default_namespace__
     * @param string $__template__
     */
    public function __construct(protected array $__namespaces__,
        protected string $__default_namespace__, protected string $__template__)
    {}

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->__properties__[$name] ?? '';
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->__properties__[$name] = $value;
    }

    /**
     * Include a template
     *
     * @param string $template The name of template to be rendered
     * @param array $vars The template vars
     *
     * @return void
     */
    protected function include(string $template, array $vars = []): void
    {
        $context = new Context($this->__namespaces__,
            $this->__default_namespace__, $template);
        echo $context->__render($vars);
    }

    /**
     * @param string $template The name of template to be rendered
     *
     * @return void
     */
    public function extends(string $template): void
    {
        $this->__extends__ = new Context($this->__namespaces__,
            $this->__default_namespace__, $template);
    }

    /**
     * Start a new block
     *
     * @param string $name
     *
     * @return void
     */
    public function block(string $name): void
    {
        ob_start();
        $this->__block_name__ = $name;
    }

    /**
     * End the current block
     *
     * @param Closure|null $filter
     *
     * @return void
     */
    public function endblock(?Closure $filter = null): void
    {
        $content = ob_get_clean();
        $this->__set($this->__block_name__, !$filter ? $content : $filter($content));
    }

    /**
     * @return string
     */
    private function __path(): string
    {
        $template = trim($this->__template__);
        // Get the namespace name
        $namespace = $this->__default_namespace__;
        $separatorPosition = strrpos($template, '::');
        if($separatorPosition !== false)
        {
            $namespace = substr($template, 0, $separatorPosition);
            $template = substr($template, $separatorPosition + 2);
        }
        // Check if the namespace is defined
        if(!isset($this->__namespaces__[$namespace]))
        {
            return $template;
        }

        $namespace = $this->__namespaces__[$namespace];
        // Get the template path
        return $namespace['directory'] . $template . $namespace['extension'];
    }

    /**
     * Render a template
     *
     * @param array $vars The template vars
     *
     * @return string
     */
    public function __render(array $vars): string
    {
        // Get the template path
        $templatePath = $this->__path();
        if(!@is_readable($templatePath))
        {
            return '';
        }

        // Save the template properties.
        foreach($vars as $name => $value)
        {
            $this->__set((string)$name, $value);
        }

        // Render the template
        $renderer = function() use($templatePath) {
            ob_start();
            include $templatePath;
            $content = ob_get_clean();

            return $this->__extends__ === null ? $content :
                // Render the extended template with the same properties.
                $this->__extends__->__render($this->__properties__);
        };

        // Call the closure in the context of this object.
        // So the keyword '$this' in the template will refer to this object.
        return call_user_func($renderer->bindTo($this));
    }
}
