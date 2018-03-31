<?php

namespace Jaxon\Sentry;

use Jaxon\Jaxon;
use Jaxon\Utils\Traits\View;
use Jaxon\Utils\Traits\Session;
use Jaxon\Utils\Traits\Manager;
use Jaxon\Utils\Traits\Event;
use Jaxon\Utils\Traits\Validator;
use Jaxon\Utils\Config\Config;

use stdClass;
use Exception;
use Closure;

class Sentry
{
    use View, Session, Manager, Event, Validator;

    protected $aClassInitCallbacks = [];
    protected $xBeforeCallback = null;
    protected $xAfterCallback = null;
    protected $xInitCallback = null;
    protected $xInvalidCallback = null;
    protected $xErrorCallback = null;

    // Requested class and method
    private $xRequestObject = null;
    private $sRequestMethod = null;

    protected $aClassOptions = [];

    protected $appConfig = null;
    protected $xResponse = null;

    protected $aViewRenderers = array();
    protected $aViewNamespaces = array();

    // The Dependency Injection Container
    private $di = null;

    public function __construct()
    {
        $this->di = new \Pimple\Container();
    }

    /**
     * Setup the library.
     *
     * @return void
     */
    public function setup()
    {
        $jaxon = jaxon();

        // Create the Jaxon response
        $this->xResponse = $jaxon->getResponse();

        // Add the view renderer
        $this->addViewRenderer('sentry', function () {
            return new \Jaxon\Sentry\View\View();
        });

        // Set the pagination view namespace
        $this->addViewNamespace('pagination', '', '', 'sentry');

        // Set the pagination renderer
        $jaxon->setPaginationRenderer(function () {
            return new \Jaxon\Sentry\Pagination\Renderer();
        });
    }

    /**
     * Get the Jaxon response.
     *
     * @return Jaxon\Response\Response
     */
    public function ajaxResponse()
    {
        return $this->xResponse;
    }

    /**
     * Add a class namespace.
     *
     * @param string            $sDirectory             The path to the directory
     * @param string            $sNamespace             The associated namespace
     * @param string            $sSeparator             The character to use as separator in javascript class names
     * @param array             $aProtected             The functions that are not to be exported
     *
     * @return void
     */
    public function addClassNamespace($sDirectory, $sNamespace, $sSeparator = '.', array $aProtected = array())
    {
        // Valid separator values are '.' and '_'. Any other value is considered as '.'.
        $sSeparator = trim($sSeparator);
        if($sSeparator != '_')
        {
            $sSeparator = '.';
        }
        jaxon()->addClassDir(trim($sDirectory), trim($sNamespace), $sSeparator, $aProtected);
    }

    /**
     * Read class namespaces from config values.
     *
     * @param Config            $xAppConfig             The application config options
     *
     * @return void
     */
    public function addClassNamespaces($xAppConfig)
    {
        if($xAppConfig->hasOption('classes') && is_array($xAppConfig->getOption('classes')))
        {
            $aNamespaces = $xAppConfig->getOption('classes');

            // The public methods of the base class must not be exported to javascript
            $protected = array();
            $baseClass = new \ReflectionClass('\\Jaxon\\Sentry\\Armada');
            foreach ($baseClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $xMethod)
            {
                $protected[] = $xMethod->getShortName();
            }
            foreach($aNamespaces as $aNamespace)
            {
                // Check mandatory options
                if(!key_exists('directory', $aNamespace) || !key_exists('namespace', $aNamespace))
                {
                    continue;
                }
                // Set the default values for optional parameters
                if(!key_exists('separator', $aNamespace))
                {
                    $aNamespace['separator'] = '.';
                }
                if(!key_exists('protected', $aNamespace))
                {
                    $aNamespace['protected'] = [];
                }
                $this->addClassNamespace($aNamespace['directory'], $aNamespace['namespace'],
                    $aNamespace['separator'], array_merge($aNamespace['protected'], $protected));
            }
        }
    }

    /**
     * Read class namespaces from config values.
     *
     * @param Config            $xAppConfig             The application config options
     *
     * @return void
     */
    public function setClassNamespaces($xAppConfig)
    {
        $this->addClassNamespaces($xAppConfig);
    }

    /**
     * Read class options from config values.
     *
     * @param array             $aOptions               The application config options
     *
     * @return void
     */
    public function mergeClassOptions($aOptions)
    {
        // The new options overwrite those already saved, and the merge
        // shall be done at option level, and not at class level.
        foreach($aOptions as $class => $aClassOption)
        {
            if(!array_key_exists($class, $this->aClassOptions))
            {
                $this->aClassOptions[$class] = $aClassOption;
            }
            else
            {
                foreach($aClassOption as $key => $aOption)
                {
                    // Merge the options
                    $this->aClassOptions[$class][$key] = array_key_exists($key, $this->aClassOptions[$class]) ?
                        array_merge($this->aClassOptions[$class][$key], $aOption) : $aOption;
                }
            }
        }
    }

    /**
     * Read class options from config values.
     *
     * @param Config            $xAppConfig             The application config options
     *
     * @return void
     */
    public function addClassOptions($xAppConfig)
    {
        $aOptions = $xAppConfig->getOption('options.classes', []);
        $this->mergeClassOptions($aOptions);
    }

    /**
     * Add a view namespace, and set the corresponding renderer.
     *
     * @param string        $sNamespace         The namespace name
     * @param string        $sDirectory         The namespace directory
     * @param string        $sExtension         The extension to append to template names
     * @param string        $sRenderer          The corresponding renderer name
     *
     * @return void
     */
    public function addViewNamespace($sNamespace, $sDirectory, $sExtension, $sRenderer)
    {
        $aNamespace = array(
            'namespace' => $sNamespace,
            'directory' => $sDirectory,
            'extension' => $sExtension,
        );
        if(key_exists($sRenderer, $this->aViewNamespaces))
        {
            $this->aViewNamespaces[$sRenderer][] = $aNamespace;
        }
        else
        {
            $this->aViewNamespaces[$sRenderer] = array($aNamespace);
        }
        $this->aViewRenderers[$sNamespace] = $sRenderer;
    }

    /**
     * Set the view namespaces.
     *
     * @param Config            $xAppConfig             The application config options
     *
     * @return void
     */
    public function addViewNamespaces($xAppConfig)
    {
        $sDefaultNamespace = $xAppConfig->getOption('options.views.default', false);
        if(is_array($namespaces = $xAppConfig->getOptionNames('views')))
        {
            foreach($namespaces as $namespace => $option)
            {
                // If no default namespace is defined, use the first one as default.
                if($sDefaultNamespace == false)
                {
                    $sDefaultNamespace = $namespace;
                }
                // Save the namespace
                $directory = $xAppConfig->getOption($option . '.directory');
                $extension = $xAppConfig->getOption($option . '.extension', '');
                $renderer = $xAppConfig->getOption($option . '.renderer', 'jaxon');
                $this->addViewNamespace($namespace, $directory, $extension, $renderer);
            }
        }

        // Save the view renderers and namespaces in the DI container
        $this->initViewRenderers($this->aViewRenderers);
        $this->initViewNamespaces($this->aViewNamespaces, $sDefaultNamespace);
    }

    /**
     * Set the view namespaces.
     *
     * @param Config            $xAppConfig             The application config options
     *
     * @return void
     */
    public function setViewNamespaces($xAppConfig)
    {
        $this->addViewNamespaces($xAppConfig);
    }

    /**
     * Set the Jaxon library default options.
     *
     * @return void
     */
    public function setLibraryOptions($bExtern, $bMinify, $sJsUri, $sJsDir)
    {
        $jaxon = jaxon();
        // Jaxon library settings
        if(!$jaxon->hasOption('js.app.extern'))
        {
            $jaxon->setOption('js.app.extern', $bExtern);
        }
        if(!$jaxon->hasOption('js.app.minify'))
        {
            $jaxon->setOption('js.app.minify', $bMinify);
        }
        if(!$jaxon->hasOption('js.app.uri'))
        {
            $jaxon->setOption('js.app.uri', $sJsUri);
        }
        if(!$jaxon->hasOption('js.app.dir'))
        {
            $jaxon->setOption('js.app.dir', $sJsDir);
        }

        // Set the request URI
        if(!$jaxon->hasOption('core.request.uri'))
        {
            $jaxon->setOption('core.request.uri', 'jaxon');
        }
    }

    /**
     * Add a callback to initialise specific class instances.
     *
     * @param string            $sNamespace         The namespace name
     * @param callable          $callable           The callback function
     *
     * @return void
     */
    public function addClassInitializer($sNamespace, $callable)
    {
        $sNamespace = trim($sNamespace, '\\ ') . '\\';
        $this->aClassInitCallbacks[$sNamespace] = $callable;
    }

    /**
     * Set the init callback, used to initialise class instances.
     *
     * @param callable          $callable               The callback function
     *
     * @return void
     */
    public function setInitCallback($callable)
    {
        $this->xInitCallback = $callable;
    }

    /**
     * Set the pre-request processing callback.
     *
     * @param callable          $callable               The callback function
     *
     * @return void
     */
    public function setBeforeCallback($callable)
    {
        $this->xBeforeCallback = $callable;
    }

    /**
     * Set the post-request processing callback.
     *
     * @param callable          $callable               The callback function
     *
     * @return void
     */
    public function setAfterCallback($callable)
    {
        $this->xAfterCallback = $callable;
    }

    /**
     * Set the processing error callback.
     *
     * @param callable          $callable               The callback function
     *
     * @return void
     */
    public function setInvalidCallback($callable)
    {
        $this->xInvalidCallback = $callable;
    }

    /**
     * Set the processing exception callback.
     *
     * @param callable          $callable               The callback function
     *
     * @return void
     */
    public function setErrorCallback($callable)
    {
        $this->xErrorCallback = $callable;
    }

    /**
     * Initialize a class instance.
     *
     * @return void
     */
    protected function initInstance(Armada $instance)
    {
        // Return if the class instance has already been initialized.
        if(!($instance) || ($instance->response))
        {
            return;
        }
        // Init the class instance
        $instance->response = $this->xResponse;
        if(($this->xInitCallback))
        {
            call_user_func_array($this->xInitCallback, array($instance));
        }
        // Apply class initializer
        foreach($this->aClassInitCallbacks as $namespace => $callback)
        {
            $classname = get_class($instance);
            if(strpos($classname, $namespace) !== false)
            {
                // Call the callback passing the class instance as parameter
                $callback($instance);
            }
        }
        // Call the init() function of the instance class
        $instance->init();
    }

    /**
     * Get a class instance.
     *
     * @param  string  $classname the class name
     *
     * @return Jaxon\Sentry\Armada|null  The registered instance of the class
     */
    public function instance($classname)
    {
        // Find the class instance, and register the class if the instance is not found.
        if(!($instance = jaxon()->getPluginManager()->getRegisteredObject($classname)))
        {
            $instance = jaxon()->registerClass($classname, [], true);
        }
        if(($instance))
        {
            $this->initInstance($instance);
        }
        return $instance;
    }

    /**
     * Get a Jaxon request to a given class.
     *
     * @param  string  $classname the class name
     *
     * @return Jaxon\Request\Request|null  The request to the class
     */
    public function request($classname)
    {
        $instance = $this->instance($classname);
        return ($instance != null ? $instance->request() : null);
    }

    /**
     * This is the pre-request processing callback passed to the Jaxon library.
     *
     * @param  boolean  &$bEndRequest if set to true, the request processing is interrupted.
     *
     * @return Jaxon\Response\Response  the Jaxon response
     */
    public function onEventBefore(&$bEndRequest)
    {
        // Validate the inputs
        $class = $_POST['jxncls'];
        $method = $_POST['jxnmthd'];
        if(!$this->validateClass($class) || !$this->validateMethod($method))
        {
            // End the request processing if the input data are not valid.
            // Todo: write an error message in the response
            $bEndRequest = true;
            return $this->xResponse;
        }
        // Instanciate the class. This will include the required file.
        $this->xRequestObject = $this->instance($class);
        $this->sRequestMethod = $method;
        if(!$this->xRequestObject)
        {
            // End the request processing if the class cannot be found.
            // Todo: write an error message in the response
            $bEndRequest = true;
            return $this->xResponse;
        }

        // Call the user defined callback
        if(($this->xBeforeCallback))
        {
            call_user_func_array($this->xBeforeCallback, array($this->xRequestObject, $this->sRequestMethod, &$bEndRequest));
        }
        return $this->xResponse;
    }

    /**
     * This is the post-request processing callback passed to the Jaxon library.
     *
     * @return Jaxon\Response\Response  the Jaxon response
     */
    public function onEventAfter()
    {
        if(($this->xAfterCallback))
        {
            call_user_func_array($this->xAfterCallback, array($this->xRequestObject, $this->sRequestMethod));
        }
        return $this->xResponse;
    }

    /**
     * This callback is called whenever an invalid request is processed.
     *
     * @return Jaxon\Response\Response  the Jaxon response
     */
    public function onEventInvalid($sMessage)
    {
        if(($this->xInvalidCallback))
        {
            call_user_func_array($this->xInvalidCallback, array($this->xResponse, $sMessage));
        }
        return $this->xResponse;
    }

    /**
     * This callback is called whenever an invalid request is processed.
     *
     * @return Jaxon\Response\Response  the Jaxon response
     */
    public function onEventError(Exception $e)
    {
        if(($this->xErrorCallback))
        {
            call_user_func_array($this->xErrorCallback, array($this->xResponse, $e));
        }
        else
        {
            throw $e;
        }
        return $this->xResponse;
    }

    /**
     * Register the Jaxon classes.
     *
     * @return void
     */
    public function register()
    {
        jaxon()->registerClasses($this->aClassOptions);
    }

    /**
     * Process the current Jaxon request.
     *
     * @return void
     */
    public function processRequest()
    {
        // Process Jaxon Request
        $jaxon = jaxon();
        if($jaxon->canProcessRequest())
        {
            $jaxon->register(Jaxon::PROCESSING_EVENT, Jaxon::PROCESSING_EVENT_BEFORE, array($this, 'onEventBefore'));
            $jaxon->register(Jaxon::PROCESSING_EVENT, Jaxon::PROCESSING_EVENT_AFTER, array($this, 'onEventAfter'));
            $jaxon->register(Jaxon::PROCESSING_EVENT, Jaxon::PROCESSING_EVENT_INVALID, array($this, 'onEventInvalid'));
            $jaxon->register(Jaxon::PROCESSING_EVENT, Jaxon::PROCESSING_EVENT_ERROR, array($this, 'onEventError'));
            // Traiter la requete
            $jaxon->processRequest();
        }
    }

    /**
     * Register a package in the DI container
     *
     * @param string $name                  The package name
     * @param Closure $closure              The package instance, or a closure that returns the instance
     *
     * @return void
     */
    public function registerPackage($name, Closure $closure)
    {
        $this->di['package.' . $name] = $closure;
    }

    /**
     * Get a package instance from the DI container
     *
     * @param string $name                  The package name
     *
     * @return mixed   The package instance
     */
    public function getPackage($name)
    {
        return $this->di['package.' . $name];
    }
}
