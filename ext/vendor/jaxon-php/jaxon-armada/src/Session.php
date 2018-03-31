<?php

namespace Jaxon\Armada;

class Session
{
    /**
     * The session manager implementation
     *
     * It is provided by the aura/session package https://packagist.org/packages/aura/session
     *
     * @var \Aura\Session\Session
     */
    protected $xSession = null;

    /**
     * The session manager implementation
     *
     * It is provided by the aura/session package https://packagist.org/packages/aura/session
     *
     * @var \Aura\Session\Session
     */
    protected $xSegment = null;

    /**
     * The constructor
     */
    public function __construct()
    {
        $xSessionFactory = new \Aura\Session\SessionFactory;
        $this->xSession = $xSessionFactory->newInstance($_COOKIE);
        $this->xSegment = $this->xSession->getSegment(get_class($this));
    }

    /**
     * Get the current session id
     *
     * @return string           The session id
     */
    public function getId()
    {
        return $this->xSession->getId();
    }

    /**
     * Generate a new session id
     *
     * @param bool          $bDeleteData         Whether to delete data from the previous session
     *
     * @return void
     */
    public function newId($bDeleteData = false)
    {
        if($bDeleteData)
        {
            $this->clear();
        }
        $this->xSession->regenerateId();
    }

    /**
     * Start the session
     *
     * @return void
     */
    public function start()
    {
        $this->xSession->start();
    }

    /**
     * Save data in the session
     *
     * @param string        $sKey                The session key
     * @param string        $xValue              The session value
     *
     * @return void
     */
    public function set($sKey, $xValue)
    {
        $this->xSegment->set($sKey, $xValue);
    }

    /**
     * Check if a session key exists
     *
     * @param string        $sKey                The session key
     *
     * @return bool             True if the session key exists, else false
     */
    public function has($sKey)
    {
        return key_exists($sKey, $_SESSION[get_class($this)]);
    }

    /**
     * Get data from the session
     *
     * @param string        $sKey                The session key
     * @param string        $xDefault            The default value
     *
     * @return mixed|$xDefault             The data under the session key, or the $xDefault parameter
     */
    public function get($sKey, $xDefault = null)
    {
        return $this->xSegment->get($sKey, $xDefault);
    }

    /**
     * Get all data in the session
     *
     * @return array             An array of all data in the session
     */
    public function all()
    {
        return $_SESSION[get_class($this)];
    }

    /**
     * Delete a session key and its data
     *
     * @param string        $sKey                The session key
     *
     * @return void
     */
    public function delete($sKey)
    {
        if($this->has($sKey))
        {
            unset($_SESSION[get_class($this)][$sKey]);
        }
    }

    /**
     * Delete all data in the session
     *
     * @return void
     */
    public function clear()
    {
        $this->xSession->clear();
    }

    /**
     * Delete all data in the session
     *
     * @return void
     */
    public function destroy()
    {
        $this->xSession->destroy();
    }
}
