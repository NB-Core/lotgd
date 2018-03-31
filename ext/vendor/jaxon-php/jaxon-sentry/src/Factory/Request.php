<?php

/**
 * Factory.php - Jaxon Request Factory
 *
 * Create Jaxon client side requests to a given class.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2016 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-sentry
 */

namespace Jaxon\Sentry\Factory;

use Jaxon\Sentry\Armada;

class Request
{
    /**
     * The class instance this request factory is attached to
     *
     * @var Jaxon\Sentry\Armada
     */
    private $instance = null;

    /**
     * The reflection class
     *
     * @var ReflectionClass
     */
    // private $reflectionClass;

    /**
     * Create a new Factory instance.
     *
     * @return void
     */
    public function __construct(Armada $instance)
    {
        $this->instance = $instance;
        // $this->reflectionClass = new \ReflectionClass(get_class($instance));
    }

    /**
     * Generate the corresponding javascript code for a call to any method
     *
     * @return string
     */
    public function __call($sMethod, $aArguments)
    {
        // Check if the method exists in the class, and is public
        /*if(!$this->reflectionClass->hasMethod($sMethod))
        {
            // Todo: throw an exception
        }
        if(!$this->reflectionClass->getMethod($sMethod)->isPublic())
        {
            // Todo: throw an exception
        }*/
        // Prepend the class name to the method name
        $sMethod = $this->instance->getJaxonClassName() . '.' . $sMethod;
        // Make the request
        return call_user_func_array('\Jaxon\Request\Factory::call', array_merge(array($sMethod), $aArguments));
    }
}
