<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\ServerFunctions;
use Lotgd\Settings;

require_once __DIR__ . '/../config/constants.php';

class ServerDummySettings extends Settings
{
    private array $values;
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }
    public function getSetting(string|int $settingname, mixed $default = false): mixed
    {
        return $this->values[$settingname] ?? $default;
    }
    public function loadSettings(): void
    {
    }
    public function clearSettings(): void
    {
    }
    public function saveSetting(string|int $settingname, mixed $value): bool
    {
        $this->values[$settingname] = $value;
        return true;
    }
    public function getArray(): array
    {
        return $this->values;
    }
}

final class ServerFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER = [];
        \Lotgd\MySQL\Database::$onlineCounter = 0;
        \Lotgd\MySQL\Database::$settings_table = [];
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
