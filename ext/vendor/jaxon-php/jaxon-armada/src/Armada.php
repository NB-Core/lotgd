<?php

namespace Jaxon\Armada;

class Armada
{
    use \Jaxon\Sentry\Traits\Armada;

    /**
     * The config file path
     *
     * @var string
     */
    protected $sConfigFile = '';

    /**
     * Initialise the Jaxon module.
     *
     * @return void
     */
    public function __construct()
    {}

    /**
     * Set the config file path.
     *
     * @return void
     */
    public function config($sConfigFile)
    {
        $this->sConfigFile = $sConfigFile;
        // Initialize the Jaxon plugin
        $this->_jaxonSetup();
    }

    /**
     * Set the module specific options for the Jaxon library.
     *
     * @return void
     */
    protected function jaxonSetup()
    {
        $jaxon = jaxon();
        $sentry = $jaxon->sentry();

        // Read config file
        $this->appConfig = $jaxon->readConfigFile($this->sConfigFile, 'lib', 'app');

        // Set the session manager
        $sentry->setSessionManager(function () {
            return new Session();
        });
    }

    /**
     * Set the module specific options for the Jaxon library.
     *
     * This method needs to set at least the Jaxon request URI.
     *
     * @return void
     */
    protected function jaxonCheck()
    {
        // Check the mandatory options
        // Jaxon library settings
        /*$aMandatoryOptions = ['js.app.extern', 'js.app.minify', 'js.app.uri', 'js.app.dir'];
        foreach($aMandatoryOptions as $sOption)
        {
            if(!$jaxon->hasOption($sOption))
            {
                throw new \Jaxon\Exception\Config\Data(jaxon_trans('config.errors.data.missing', array('key' => 'lib:' . $sOption)));
            }
        }*/
        // Jaxon application settings
        /*$aMandatoryOptions = ['controllers.directory', 'controllers.namespace'];
        foreach($aMandatoryOptions as $sOption)
        {
            if(!$this->appConfig->hasOption($sOption))
            {
                throw new \Jaxon\Exception\Config\Data(jaxon_trans('config.errors.data.missing', array('key' => 'app:' . $sOption)));
            }
        }*/
    }

    /**
     * Wrap the Jaxon response into an HTTP response.
     *
     * @param  $code        The HTTP Response code
     *
     * @return HTTP Response
     */
    public function httpResponse($code = '200')
    {
        // Send HTTP Headers
        jaxon()->sendResponse();
    }
}
