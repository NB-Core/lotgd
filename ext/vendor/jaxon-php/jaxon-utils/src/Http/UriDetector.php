<?php

/**
 * UriDetector.php - Jaxon request UriDetector detector
 *
 * Detect and parse the URI of the Jaxon request being processed.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2022 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Utils\Http;

use function basename;
use function explode;
use function implode;
use function parse_str;
use function parse_url;
use function rawurlencode;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;

class UriDetector
{
    /**
     * The URL components
     *
     * @var array
     */
    protected $aUrl;

    /**
     * @param array $aServerParams The server environment variables
     *
     * @return void
     */
    private function setScheme(array $aServerParams)
    {
        if(isset($this->aUrl['scheme']))
        {
            return;
        }
        if(isset($aServerParams['HTTP_SCHEME']))
        {
            $this->aUrl['scheme'] = $aServerParams['HTTP_SCHEME'];
            return;
        }
        if((isset($aServerParams['HTTPS']) && strtolower($aServerParams['HTTPS']) === 'on') ||
            (isset($aServerParams['HTTP_X_FORWARDED_SSL']) && $aServerParams['HTTP_X_FORWARDED_SSL'] === 'on') ||
            (isset($aServerParams['HTTP_X_FORWARDED_PROTO']) && $aServerParams['HTTP_X_FORWARDED_PROTO'] === 'https'))
        {
            $this->aUrl['scheme'] = 'https';
            return;
        }
        $this->aUrl['scheme'] = 'http';
    }

    /**
     * Get the URL from the $aServerParams var
     *
     * @param array $aServerParams The server environment variables
     * @param string $sKey The key in the $aServerParams array
     *
     * @return void
     */
    private function setHostFromServer(array $aServerParams, string $sKey)
    {
        if(isset($this->aUrl['host']) || empty($aServerParams[$sKey]))
        {
            return;
        }
        if(strpos($aServerParams[$sKey], ':') === false)
        {
            $this->aUrl['host'] = $aServerParams[$sKey];
            return;
        }
        list($this->aUrl['host'], $this->aUrl['port']) = explode(':', $aServerParams[$sKey]);
    }

    /**
     * @param array $aServerParams The server environment variables
     *
     * @return void
     * @throws UriException
     */
    private function setHost(array $aServerParams)
    {
        $this->setHostFromServer($aServerParams, 'HTTP_X_FORWARDED_HOST');
        $this->setHostFromServer($aServerParams, 'HTTP_HOST');
        $this->setHostFromServer($aServerParams, 'SERVER_NAME');
        if(empty($this->aUrl['host']))
        {
            throw new UriException();
        }
        if(empty($this->aUrl['port']) && isset($aServerParams['SERVER_PORT']))
        {
            $this->aUrl['port'] = $aServerParams['SERVER_PORT'];
        }
    }

    /**
     * @param array $aServerParams The server environment variables
     *
     * @return void
     */
    private function setPath(array $aServerParams)
    {
        if(isset($this->aUrl['path']) && strlen(basename($this->aUrl['path'])) === 0)
        {
            unset($this->aUrl['path']);
        }
        if(isset($this->aUrl['path']))
        {
            return;
        }
        $aPath = parse_url($aServerParams['PATH_INFO'] ?? ($aServerParams['PHP_SELF'] ?? ''));
        if(isset($aPath['path']))
        {
            $this->aUrl['path'] = $aPath['path'];
        }
    }

    /**
     * @return string
     */
    private function getPath(): string
    {
        return '/' . ltrim($this->aUrl['path'], '/');
    }

    /**
     * @return string
     */
    private function getUser(): string
    {
        if(empty($this->aUrl['user']))
        {
            return '';
        }
        $sUrl = $this->aUrl['user'];
        if(isset($this->aUrl['pass']))
        {
            $sUrl .= ':' . $this->aUrl['pass'];
        }
        return $sUrl . '@';
    }

    /**
     * @return string
     */
    private function getPort(): string
    {
        if(isset($this->aUrl['port']) &&
            (($this->aUrl['scheme'] === 'http' && $this->aUrl['port'] != 80) ||
                ($this->aUrl['scheme'] === 'https' && $this->aUrl['port'] != 443)))
        {
            return ':' . $this->aUrl['port'];
        }
        return '';
    }

    /**
     * @param array $aServerParams The server environment variables
     *
     * @return void
     */
    private function setQuery(array $aServerParams)
    {
        if(empty($this->aUrl['query']))
        {
            $this->aUrl['query'] = $aServerParams['QUERY_STRING'] ?? '';
        }
    }

    /**
     * @return string
     */
    private function getQuery(): string
    {
        if(empty($this->aUrl['query']))
        {
            return '';
        }
        $aQueries = explode('&', $this->aUrl['query']);
        foreach($aQueries as $sKey => $sQuery)
        {
            if(substr($sQuery, 0, 11) === 'jxnGenerate')
            {
                unset($aQueries[$sKey]);
            }
        }
        if(empty($aQueries))
        {
            return '';
        }
        return '?' . implode("&", $aQueries);
    }

    /**
     * Detect the URI of the current request
     *
     * @param array $aServerParams The server environment variables
     *
     * @return string
     * @throws UriException
     */
    public function detect(array $aServerParams): string
    {
        $this->aUrl = [];
        // Try to get the request URL
        if(isset($aServerParams['REQUEST_URI']))
        {
            $this->aUrl = parse_url($aServerParams['REQUEST_URI']);
        }

        // Fill in the empty values
        $this->setScheme($aServerParams);
        $this->setHost($aServerParams);
        $this->setPath($aServerParams);
        $this->setQuery($aServerParams);

        // Build the URL: Start with scheme, user and pass
        return $this->aUrl['scheme'] . '://' . $this->getUser() . $this->aUrl['host'] . $this->getPort() .
            str_replace(['"', "'", '<', '>'], ['%22', '%27', '%3C', '%3E'], $this->getPath() . $this->getQuery());
    }

    /**
     * @param string $sQueryPart
     * @param array $aServerParams
     *
     * @return string
     */
    private function parseQueryPart(string $sQueryPart, array $aServerParams): string
    {
        $aQueryParts = [];
        parse_str($sQueryPart, $aQueryParts);
        if(empty($aQueryParts))
        {
            // Couldn't break up the query, but there's one there.
            // Possibly "http://url/page.html?query1234" type of query?
            // Try to get data from the server environment var.
            parse_str($aServerParams['QUERY_STRING'] ?? '', $aQueryParts);
        }
        if(($aQueryParts))
        {
            $aNewQueryParts = [];
            foreach($aQueryParts as $sKey => $sValue)
            {
                $sValue = rawurlencode($sValue);
                $aNewQueryParts[] = rawurlencode($sKey) . ($sValue ? '=' . $sValue : $sValue);
            }
            return '?' . implode('&', $aNewQueryParts);
        }
        return trim($sQueryPart);
    }

    /**
     * Make the specified URL suitable for redirect
     *
     * @param string $sURL The relative or fully qualified URL
     * @param array $aServerParams The server environment variables
     *
     * @return string
     */
    public function redirect(string $sURL, array $aServerParams): string
    {
        // We need to parse the query part so that the values are rawurlencode()'ed.
        // Can't just use parse_url() cos we could be dealing with a relative URL which parse_url() can't deal with.
        $sURL = trim($sURL);
        $nQueryStart = strpos($sURL, '?', strrpos($sURL, '/'));
        if($nQueryStart === false)
        {
            return $sURL;
        }
        $nQueryStart++;
        $nQueryEnd = strpos($sURL, '#', $nQueryStart);
        if($nQueryEnd === false)
        {
            $nQueryEnd = strlen($sURL);
        }
        $sQueryPart = substr($sURL, $nQueryStart, $nQueryEnd - $nQueryStart);
        return str_replace('?' . $sQueryPart, $this->parseQueryPart($sQueryPart, $aServerParams), $sURL);
    }
}
