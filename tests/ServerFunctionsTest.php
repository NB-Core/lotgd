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
