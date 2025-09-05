<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Http;

/**
 * Provide functions for setting up PHP globals when running under various web servers.
 */
class PhpGenericEnvironment
{
    /**
     * Current PATH_INFO value.
     */
    private static string $pathInfo = '';

    /**
     * Current SCRIPT_NAME value.
     */
    private static string $scriptName = '';

    /**
     * Current REQUEST_URI value.
     */
    private static string $requestUri = '';

    /**
     * Reference to the $_SERVER superglobal.
     *
     * @var array<string,mixed>
     */
    private static array $server = [];

    /**
     * Reference to the session array.
     *
     * @var array<string,mixed>
     */
    private static array $session = [];

    /**
     * Set the current PATH_INFO value.
     */
    public static function setPathInfo(string $pathInfo): void
    {
        self::$pathInfo = $pathInfo;
    }

    /**
     * Get the current PATH_INFO value.
     */
    public static function getPathInfo(): string
    {
        return self::$pathInfo;
    }

    /**
     * Set the current SCRIPT_NAME value.
     */
    public static function setScriptName(string $scriptName): void
    {
        self::$scriptName = $scriptName;
    }

    /**
     * Get the current SCRIPT_NAME value.
     */
    public static function getScriptName(): string
    {
        return self::$scriptName;
    }

    /**
     * Set the current REQUEST_URI value.
     */
    public static function setRequestUri(string $requestUri): void
    {
        self::$requestUri = $requestUri;
    }

    /**
     * Get the current REQUEST_URI value.
     */
    public static function getRequestUri(): string
    {
        return self::$requestUri;
    }

    /**
     * Retrieve a value from the server superglobal.
     */
    public static function getServer(string $key, mixed $default = null): mixed
    {
        return self::$server[$key] ?? $default;
    }

    /**
     * Set a value in the server superglobal.
     */
    public static function setServer(string $key, mixed $value): void
    {
        self::$server[$key] = $value;
    }

    /**
     * Access the session array by reference.
     *
     * @return array<string,mixed>
     */
    public static function &getSession(): array
    {
        return self::$session;
    }

    /**
     * Normalise REQUEST_URI and SCRIPT_NAME when running under unusual setups.
     */
    public static function sanitizeUri(): void
    {
        if (self::$pathInfo !== '') {
            self::$scriptName = self::$pathInfo;
            self::$requestUri = '';
        }

        if (self::$requestUri === '') {
            // necessary for some IIS installations
            $get = Http::allGet();
            if (count($get) > 0) {
                self::$requestUri = self::$scriptName . '?';
                $i = 0;
                foreach ($get as $key => $val) {
                    if ($i > 0) {
                        self::$requestUri .= '&';
                    }
                    self::$requestUri .= "$key=" . URLEncode($val);
                    $i++;
                }
            } else {
                self::$requestUri = self::$scriptName;
            }
            self::$server['REQUEST_URI'] = self::$requestUri;
        }

        self::$scriptName = basename(self::$scriptName);
        if (strpos(self::$requestUri, '?')) {
            self::$requestUri = self::$scriptName . substr(self::$requestUri, strpos(self::$requestUri, '?'));
        } else {
            self::$requestUri = self::$scriptName;
        }

        self::$server['REQUEST_URI'] = self::$requestUri;
        $GLOBALS['SCRIPT_NAME'] = self::$scriptName;
        $GLOBALS['REQUEST_URI'] = self::$requestUri;
    }

    /**
     * Register global variables and sanitise the URI.
     */
    public static function setup(?array &$session = null): void
    {
        self::$server = &$_SERVER;
        if ($session === null && isset($GLOBALS['session']) && is_array($GLOBALS['session'])) {
            self::$session = &$GLOBALS['session'];
        } elseif ($session !== null) {
            self::$session = &$session;
        }

        self::$pathInfo = $GLOBALS['PATH_INFO'] ?? '';
        self::$scriptName = $GLOBALS['SCRIPT_NAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? '');
        self::$requestUri = $GLOBALS['REQUEST_URI'] ?? ($_SERVER['REQUEST_URI'] ?? '');

        RegisterGlobal::register(self::$server);
        self::sanitizeUri();
    }
}
