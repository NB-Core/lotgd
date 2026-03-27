<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ServerFunctions;
use Lotgd\Tests\Stubs\ServerDummySettings;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class ServerFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER = [];
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$onlineCounter = 0;
        \Lotgd\MySQL\Database::$settings_table = [];
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
    }

    public function testIsSecureConnection(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(ServerFunctions::isSecureConnection());

        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 443;
        $this->assertTrue(ServerFunctions::isSecureConnection());

        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = 80;
        $this->assertFalse(ServerFunctions::isSecureConnection());
    }

    public function testIsHttpsRequestUnderstandsForwardedProto(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(ServerFunctions::isHttpsRequest());

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https,http';
        $this->assertTrue(ServerFunctions::isHttpsRequest());

        $_SERVER = ['HTTP_FORWARDED' => 'for=1.2.3.4;proto=https;by=5.6.7.8'];
        $this->assertTrue(ServerFunctions::isHttpsRequest());

        $_SERVER = ['FORWARDED_PROTO' => 'https'];
        $this->assertTrue(ServerFunctions::isHttpsRequest());

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = 80;
        $this->assertFalse(ServerFunctions::isHttpsRequest());
    }

    public function testIsTheServerFull(): void
    {
        $settings = new ServerDummySettings([
            'OnlineCountLast' => 0,
            'maxonline' => 5,
            'LOGINTIMEOUT' => 900,
        ]);
        $GLOBALS['settings'] = $settings;

        \Lotgd\MySQL\Database::$onlineCounter = 3;
        $this->assertFalse(ServerFunctions::isTheServerFull());
        $this->assertSame(3, $settings->getSetting('OnlineCount'));

        $settings->saveSetting('OnlineCount', 6);
        $settings->saveSetting('maxonline', 6);
        $this->assertTrue(ServerFunctions::isTheServerFull());
    }
}
