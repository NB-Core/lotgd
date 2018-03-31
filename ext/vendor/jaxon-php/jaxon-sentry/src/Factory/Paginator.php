<?php

/**
 * Paginator.php - Jaxon Request Factory
 *
 * Create pagination links to a given class.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2016 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-sentry
 */

namespace Jaxon\Sentry\Factory;

use Jaxon\Sentry\Armada;

class Paginator
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
     * The total number of items
     *
     * @var integer
     */
    private $nItemsTotal = 0;

    /**
     * The number of items per page
     *
     * @var integer
     */
    private $nItemsPerPage = 0;

    /**
     * The current page
     *
     * @var integer
     */
    private $nCurrentPage = 0;

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
     * Set the paginator properties
     *
     * @param integer $nItemsTotal the total number of items
     * @param integer $nItemsPerPage the number of items per page
     * @param integer $nCurrentPage the current page
     *
     * @return void
     */
    public function setPaginationProperties($nItemsTotal, $nItemsPerPage, $nCurrentPage)
    {
        $this->nItemsTotal = $nItemsTotal;
        $this->nItemsPerPage = $nItemsPerPage;
        $this->nCurrentPage = $nCurrentPage;
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
        // Add the paginator options to the method arguments
        $aArguments = array_merge(array($this->nItemsTotal, $this->nItemsPerPage, $this->nCurrentPage, $sMethod), $aArguments);
        // Make the request
        return call_user_func_array('\Jaxon\Request\Factory::paginate', $aArguments);
    }
}
