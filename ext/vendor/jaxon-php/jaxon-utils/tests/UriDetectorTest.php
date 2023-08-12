<?php

namespace Jaxon\Utils\Tests;

use Jaxon\Utils\Http\UriDetector;
use Jaxon\Utils\Http\UriException;
use PHPUnit\Framework\TestCase;

final class UriDetectorTest extends TestCase
{
    /**
     * @var UriDetector
     */
    protected $xUriDetector;

    protected function setUp(): void
    {
        $this->xUriDetector = new UriDetector();
    }

    /**
     * @throws UriException
     */
    public function testUri()
    {
        $this->assertEquals('http://example.test/path', $this->xUriDetector->detect([
            'REQUEST_URI' => 'http://example.test/path'
        ]));
    }

    /**
     * @throws UriException
     */
    public function testUriWithParam()
    {
        $this->assertEquals('http://example.test/path?param1=value1&param2=%22value2%22',
            $this->xUriDetector->detect([
                'REQUEST_URI' => 'http://example.test/path?param1=value1&param2="value2"',
            ])
        );
    }

    /**
     * @throws UriException
     */
    public function testUriWithUser()
    {
        $this->assertEquals('http://user@example.test/path', $this->xUriDetector->detect([
            'REQUEST_URI' => 'http://user@example.test/path'
        ]));
    }

    /**
     * @throws UriException
     */
    public function testUriWithUserAndPass()
    {
        $this->assertEquals('http://user:pass@example.test/path', $this->xUriDetector->detect([
            'REQUEST_URI' => 'http://user:pass@example.test/path'
        ]));
    }

    /**
     * @throws UriException
     */
    public function testUriWithEmptyBasename()
    {
        $this->assertEquals('http://example.test/', $this->xUriDetector->detect([
            'REQUEST_URI' => 'http://example.test//'
        ]));
    }

    /**
     * @throws UriException
     */
    public function testUriWithParts()
    {
        $this->assertEquals('http://example.test/path?param1=value1&param2=%22value2%22',
            $this->xUriDetector->detect([
                'HTTP_SCHEME' => 'http',
                'HTTP_HOST' => 'example.test',
                'PATH_INFO' => '/path',
                'QUERY_STRING' => 'param1=value1&param2="value2"',
            ])
        );
        $this->assertEquals('http://example.test/path?param1=value1&param2=%22value2%22',
            $this->xUriDetector->detect([
                'HTTPS' => 'off',
                'HTTP_HOST' => 'example.test',
                'PATH_INFO' => '/path',
                'QUERY_STRING' => 'param1=value1&param2="value2"',
            ])
        );
        $this->assertEquals('https://example.test:8080/path',
            $this->xUriDetector->detect([
                'HTTPS' => 'on',
                'SERVER_NAME' => 'example.test:8080',
                'PATH_INFO' => '/path',
            ])
        );
        $this->assertEquals('https://example.test:8080/path',
            $this->xUriDetector->detect([
                'HTTPS' => 'on',
                'SERVER_NAME' => 'example.test',
                'SERVER_PORT' => '8080',
                'PATH_INFO' => '/path',
            ])
        );
        $this->assertEquals('https://example.test:8080/path',
            $this->xUriDetector->detect([
                'HTTP_X_FORWARDED_SSL' => 'on',
                'SERVER_NAME' => 'example.test:8080',
                'PATH_INFO' => '/path',
            ])
        );
        $this->assertEquals('https://example.test:8080/path',
            $this->xUriDetector->detect([
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'SERVER_NAME' => 'example.test:8080',
                'PATH_INFO' => '/path',
            ])
        );
    }

    /**
     * @throws UriException
     */
    public function testRemoveJaxonParam()
    {
        $this->assertEquals('http://example.test/path', $this->xUriDetector->detect([
            'REQUEST_URI' => 'http://example.test/path?jxnGenerate=true'
        ]));
        $this->assertEquals('http://example.test/path?param1=value1&param2=%22value2%22',
            $this->xUriDetector->detect([
                'REQUEST_URI' => 'http://example.test/path?param1=value1&jxnGenerate=true&param2="value2"',
            ])
        );
    }

    public function testErrorMissingHost()
    {
        $this->expectException(UriException::class);
        $this->xUriDetector->detect([
            'HTTPS' => 'on',
            'PATH_INFO' => '/path',
            'QUERY_STRING' => 'param1=value1&param2="value2"',
        ]);
    }

    public function testRedirectSimpleUrl()
    {
        $this->assertEquals('http://example.test/path',
            $this->xUriDetector->redirect('http://example.test/path', []));
    }

    public function testRedirectUrlWithAnchor()
    {
        $this->assertEquals('http://example.test/path?param=value#anchor',
            $this->xUriDetector->redirect('http://example.test/path?param=value#anchor', []));
    }

    public function testRedirectUrlWithParam()
    {
        $this->assertEquals('http://example.test/path?param=value',
            $this->xUriDetector->redirect('http://example.test/path?param=value', []));
    }

    public function testRedirectUrlWithParams()
    {
        $this->assertEquals('http://example.test/path?param1=value1&param2=value2',
            $this->xUriDetector->redirect('http://example.test/path?param1=value1&param2=value2', []));
    }

    public function testRedirectUrlWithSpecialChars()
    {
        $this->assertEquals('http://example.test/path?param1=%22value1%22&param2=%25value2%25#anchor',
            $this->xUriDetector->redirect('http://example.test/path?param1="value1"&param2=%value2%#anchor', []));
    }

    public function testRedirectSpecialUrl()
    {
        $this->assertEquals('http://example.test/?query1234',
            $this->xUriDetector->redirect('http://example.test/?query1234', []));

        $this->assertEquals('http://example.test/?query1234=',
            $this->xUriDetector->redirect('http://example.test/?query1234=', []));

        $this->assertEquals('http://example.test/?param=value&query1234=0',
            $this->xUriDetector->redirect('http://example.test/?param=value&query1234=0', []));
    }

    public function testRedirectEncodedUrl()
    {
        $this->assertEquals('http://example.test/path?param1=%22value1%22&param2=%25value2%25',
            $this->xUriDetector->redirect('http://example.test/path?param1=%22value1%22&param2=%25value2%25', []));
    }

    public function testRedirectUnexpectedUrl()
    {
        $this->assertEquals('http://example.test/',
            $this->xUriDetector->redirect('http://example.test/? ', []));
    }

    public function testRedirectQueryString()
    {
        $this->assertEquals('http://example.test/?param1=value1',
            $this->xUriDetector->redirect('http://example.test/? ', ['QUERY_STRING' => 'param1=value1']));
    }
}
