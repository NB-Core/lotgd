<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PhpGenericEnvironment;
use PHPUnit\Framework\TestCase;

final class PhpGenericEnvironmentTest extends TestCase
{
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalServer = [
            'PATH_INFO' => $GLOBALS['PATH_INFO'] ?? null,
            'SCRIPT_NAME' => $GLOBALS['SCRIPT_NAME'] ?? null,
            'REQUEST_URI' => $GLOBALS['REQUEST_URI'] ?? null,
            'SERVER_REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
            'REMOTE_ADDR' => $GLOBALS['REMOTE_ADDR'] ?? null,
            'PAGESTART' => $GLOBALS['pagestarttime'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->originalServer as $key => $value) {
            if ($key === 'SERVER_REQUEST_URI') {
                if ($value === null) {
                    unset($_SERVER['REQUEST_URI']);
                } else {
                    $_SERVER['REQUEST_URI'] = $value;
                }
                continue;
            }
            if ($key === 'PAGESTART') {
                if ($value === null) {
                    unset($GLOBALS['pagestarttime']);
                } else {
                    $GLOBALS['pagestarttime'] = $value;
                }
                continue;
            }
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
    }

    public function testSanitizeUriWithDirectorySeparator(): void
    {
        $_SERVER['PATH_INFO'] = '';
        $_SERVER['SCRIPT_NAME'] = '/dir/index.php';
        $_SERVER['REQUEST_URI'] = '/dir/index.php?foo=bar';

        $session = [];
        PhpGenericEnvironment::setup($session);

        $this->assertSame('index.php', PhpGenericEnvironment::getScriptName());
        $this->assertSame('index.php?foo=bar', PhpGenericEnvironment::getRequestUri());
        $this->assertSame('index.php?foo=bar', PhpGenericEnvironment::getServer('REQUEST_URI'));
        $this->assertSame('index.php', $GLOBALS['SCRIPT_NAME']);
        $this->assertSame('index.php?foo=bar', $GLOBALS['REQUEST_URI']);
    }

    public function testSanitizeUriWithoutDirectorySeparator(): void
    {
        $_SERVER['PATH_INFO'] = '';
        $_SERVER['SCRIPT_NAME'] = 'index.php';
        $_SERVER['REQUEST_URI'] = 'index.php?foo=bar';

        $session = [];
        PhpGenericEnvironment::setup($session);

        $this->assertSame('index.php', PhpGenericEnvironment::getScriptName());
        $this->assertSame('index.php?foo=bar', PhpGenericEnvironment::getRequestUri());
        $this->assertSame('index.php?foo=bar', PhpGenericEnvironment::getServer('REQUEST_URI'));
        $this->assertSame('index.php', $GLOBALS['SCRIPT_NAME']);
        $this->assertSame('index.php?foo=bar', $GLOBALS['REQUEST_URI']);
    }

    public function testSetupStoresRemoteAddrAndPageStartTime(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $GLOBALS['pagestarttime'] = 123.45;

        $session = [];
        PhpGenericEnvironment::setup($session);

        $this->assertSame('8.8.8.8', PhpGenericEnvironment::getRemoteAddr());
        $this->assertSame(123.45, PhpGenericEnvironment::getPageStartTime());
    }
}
