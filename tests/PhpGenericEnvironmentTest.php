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
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
    }

    public function testSanitizeUriWithDirectorySeparator(): void
    {
        global $PATH_INFO, $SCRIPT_NAME, $REQUEST_URI;
        $PATH_INFO = '';
        $SCRIPT_NAME = '/dir/index.php';
        $REQUEST_URI = '/dir/index.php?foo=bar';
        $_SERVER['REQUEST_URI'] = $REQUEST_URI;

        PhpGenericEnvironment::sanitizeUri();

        $this->assertSame('index.php', $SCRIPT_NAME);
        $this->assertSame('index.php?foo=bar', $REQUEST_URI);
        $this->assertSame('index.php?foo=bar', $_SERVER['REQUEST_URI']);
    }

    public function testSanitizeUriWithoutDirectorySeparator(): void
    {
        global $PATH_INFO, $SCRIPT_NAME, $REQUEST_URI;
        $PATH_INFO = '';
        $SCRIPT_NAME = 'index.php';
        $REQUEST_URI = 'index.php?foo=bar';
        $_SERVER['REQUEST_URI'] = $REQUEST_URI;

        PhpGenericEnvironment::sanitizeUri();

        $this->assertSame('index.php', $SCRIPT_NAME);
        $this->assertSame('index.php?foo=bar', $REQUEST_URI);
        $this->assertSame('index.php?foo=bar', $_SERVER['REQUEST_URI']);
    }
}
