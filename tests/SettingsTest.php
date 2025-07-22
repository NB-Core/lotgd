<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\Settings;

require_once __DIR__ . '/../config/constants.php';

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$settings_table = [];
        \Lotgd\MySQL\Database::$affected_rows = 0;
    }

    public function testGetSettingReturnsDefault(): void
    {
        $settings = new Settings('settings');
        $this->assertSame('def', $settings->getSetting('missing', 'def'));
    }

    public function testSaveSettingStoresValue(): void
    {
        $settings = new Settings('settings');
        $settings->saveSetting('alpha', 'beta');
        $this->assertSame('beta', $settings->getSetting('alpha'));
    }

    public function testClearSettingsReloadsFromDatabase(): void
    {
        \Lotgd\MySQL\Database::$settings_table = ['foo' => 'bar'];
        $settings = new Settings('settings');
        $this->assertSame('bar', $settings->getSetting('foo'));
        \Lotgd\MySQL\Database::$settings_table['foo'] = 'baz';
        $settings->clearSettings();
        $this->assertSame('baz', $settings->getSetting('foo'));
    }

    public function testLoadSettingsFetchesAfterClear(): void
    {
        \Lotgd\MySQL\Database::$settings_table = ['x' => '1'];
        $settings = new Settings('settings');
        $settings->clearSettings();
        \Lotgd\MySQL\Database::$settings_table['x'] = '2';
        $settings->loadSettings();
        $this->assertSame('2', $settings->getSetting('x'));
    }
}
