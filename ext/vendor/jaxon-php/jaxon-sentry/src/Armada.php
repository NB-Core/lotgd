<?php

namespace Jaxon\Sentry;

class Armada
{
    use \Jaxon\Request\Traits\Factory;

    /**
     * The Jaxon response returned by all classes methods
     *
     * @var Jaxon\Response\Response
     */
    public $response = null;

    /**
     * The request factory
     *
     * @var Request\Factory
     */
    private $rqFactory;

    /**
     * The paginator factory
     *
     * @var Request\Paginator
     */
    private $pgFactory;

    /**
     * Create a new instance.
     */
    public function __construct()
    {
        $this->rqFactory = new Factory\Request($this);
        $this->pgFactory = new Factory\Paginator($this);
    }

    /**
     * Initialize the instance.
     *
     * @return void
     */
    public function init()
    {}

    /**
     * Get the view renderer
     *
     * @return Jaxon\Sentry\View\Facade
     */
    public function view()
    {
        return jaxon()->sentry()->getViewRenderer();
    }

    /**
     * Get the session manager
     *
     * @return Jaxon\Sentry\Interfaces\Session
     */
    public function session()
    {
        return jaxon()->sentry()->getSessionManager();
    }

    /**
     * Get the request factory.
     *
     * @return Jaxon\Sentry\Factory\Request
     */
    public function request()
    {
        return $this->rqFactory;
    }

    /**
     * Get the request factory.
     *
     * @return Jaxon\Sentry\Factory\Request
     */
    public function rq()
    {
        return $this->request();
    }

    /**
     * Get the paginator factory.
     *
     * @param integer $nItemsTotal the total number of items
     * @param integer $nItemsPerPage the number of items per page
     * @param integer $nCurrentPage the current page
     *
     * @return Jaxon\Sentry\Factory\Paginator
     */
    public function paginator($nItemsTotal, $nItemsPerPage, $nCurrentPage)
    {
        $this->pgFactory->setPaginationProperties($nItemsTotal, $nItemsPerPage, $nCurrentPage);
        return $this->pgFactory;
    }

    /**
     * Get the paginator factory.
     *
     * @param integer $nItemsTotal the total number of items
     * @param integer $nItemsPerPage the number of items per page
     * @param integer $nCurrentPage the current page
     *
     * @return Jaxon\Sentry\Factory\Paginator
     */
    public function pg($nItemsTotal, $nItemsPerPage, $nCurrentPage)
    {
        return $this->paginator($nItemsTotal, $nItemsPerPage, $nCurrentPage);
    }

    /**
     * Create a JQuery Element with a given selector, and link it to the response attribute.
     *
     * @param string        $sSelector            The jQuery selector
     * @param string        $sContext             A context associated to the selector
     *
     * @return Jaxon\JQuery\Dom\Element
     */
    public function jq($sSelector = '', $sContext = '')
    {
        return $this->response->plugin('jquery')->element($sSelector, $sContext);
    }

    /**
     * Create a JQuery Element with a given selector, and link it to the response attribute.
     *
     * @param string        $sSelector            The jQuery selector
     * @param string        $sContext             A context associated to the selector
     *
     * @return Jaxon\JQuery\Dom\Element
     */
    public function jQuery($sSelector = '', $sContext = '')
    {
        return $this->jq($sSelector, $sContext);
    }

    /**
     * Get an instance of a Jaxon class by name
     *
     * @param string $name the class name
     *
     * @return Jaxon\Sentry\Armada|null the Jaxon class instance, or null
     */
    public function instance($name)
    {
        // If the class name starts with a dot, then find the class in the same class path as the caller
        if(substr($name, 0, 1) == '.')
        {
            $name = $this->getJaxonClassPath() . $name;
        }
        // The class namespace is prepended to the class name
        elseif(substr($name, 0, 1) == ':' && ($namespace = $this->getJaxonNamespace()))
        {
            $name = str_replace('\\', '.', trim($namespace, '\\')) . '.' . substr($name, 1);
        }
        return jaxon()->sentry()->instance($name);
    }

    /**
     * Get an instance of a Jaxon class by name
     *
     * @param string $name the class name
     *
     * @return Jaxon\Sentry\Armada|null the Jaxon class instance, or null
     */
    public function cl($name)
    {
        return $this->instance($name);
    }

   /**
     * Get the uploaded files
     *
     * @return array
     */
    public function getUploadedFiles()
    {
        return jaxon()->getUploadedFiles();
    }
}
