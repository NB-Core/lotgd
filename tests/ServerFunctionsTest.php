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
        putenv('LOTGD_TRUST_FORWARDED_HEADERS=1');
        putenv('LOTGD_TRUSTED_PROXY_IPS');
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$onlineCounter = 0;
        \Lotgd\MySQL\Database::$settings_table = [];
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
    }

    protected function tearDown(): void
    {
        putenv('LOTGD_TRUST_FORWARDED_HEADERS');
        putenv('LOTGD_TRUSTED_PROXY_IPS');
        parent::tearDown();
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

        $_SERVER = ['X_FORWARDED_PROTO' => 'https'];
        $this->assertTrue(ServerFunctions::isHttpsRequest());

        $_SERVER = ['HTTP_X_FORWARDED_PROTOCOL' => 'https'];
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

    public function testIsHttpsRequestHonorsTrustedProxyAllowlistWhenConfigured(): void
    {
        putenv('LOTGD_TRUST_FORWARDED_HEADERS=1');
        putenv('LOTGD_TRUSTED_PROXY_IPS=10.0.0.1,10.0.0.2');

        $_SERVER = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ];
        $this->assertFalse(ServerFunctions::isHttpsRequest());

        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $this->assertTrue(ServerFunctions::isHttpsRequest());
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
