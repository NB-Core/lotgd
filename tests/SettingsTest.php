<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$settings_table = [];
        \Lotgd\MySQL\Database::$affected_rows = 0;
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        if (class_exists('Lotgd\\Tests\\Stubs\\DoctrineBootstrap', false)) {
            \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        }
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
