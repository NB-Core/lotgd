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
        \Lotgd\MySQL\Database::$tableExists = true;
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

    public function testLoadSettingsHandlesMissingTable(): void
    {
        \Lotgd\MySQL\Database::$tableExists = false;
        $settings = new Settings('settings');
        $this->assertSame([], $settings->getArray());
    }

    public function testCharsetValueCoercedWhenDifferent(): void
    {
        \Lotgd\MySQL\Database::$settings_table = ['charset' => 'ISO-8859-1'];
        $settings = new Settings('settings');
        $this->assertSame('UTF-8', $settings->getSetting('charset'));
        $this->assertArrayHasKey('charset', \Lotgd\MySQL\Database::$settings_table);
        $this->assertSame('UTF-8', \Lotgd\MySQL\Database::$settings_table['charset']);
    }

    public function testCharsetValueCoercedWhenMissing(): void
    {
        \Lotgd\MySQL\Database::$settings_table = [];
        $settings = new Settings('settings');

        \Lotgd\MySQL\Database::$affected_rows = 0;
        $conn = \Lotgd\MySQL\Database::getDoctrineConnection();
        $conn->queries = [];
        $this->assertSame('UTF-8', $settings->getSetting('charset', 'ISO-8859-1'));
        $this->assertNotEmpty($conn->queries);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', end($conn->queries));
        $this->assertSame(1, \Lotgd\MySQL\Database::$affected_rows);

        \Lotgd\MySQL\Database::$affected_rows = 0;
        $queryCount = count($conn->queries);
        $this->assertSame('UTF-8', $settings->getSetting('charset'));
        $this->assertCount($queryCount, $conn->queries);
        $this->assertSame(0, \Lotgd\MySQL\Database::$affected_rows);
    }

    public function testRepeatedCharsetCallsDoNotDuplicateInsert(): void
    {
        \Lotgd\MySQL\Database::$settings_table = [];
        $settings = new Settings('settings');

        \Lotgd\MySQL\Database::$affected_rows = 0;
        $conn = \Lotgd\MySQL\Database::getDoctrineConnection();
        $conn->queries = [];
        $this->assertSame('UTF-8', $settings->getSetting('charset'));
        $this->assertNotEmpty($conn->queries);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', end($conn->queries));
        $this->assertSame(1, \Lotgd\MySQL\Database::$affected_rows);

        \Lotgd\MySQL\Database::$affected_rows = 0;
        $queryCount = count($conn->queries);
        $this->assertSame('UTF-8', $settings->getSetting('charset'));
        $this->assertCount($queryCount, $conn->queries);
        $this->assertSame(0, \Lotgd\MySQL\Database::$affected_rows);
    }

    public function testSaveSettingHandlesQuotedAndMultibyteValues(): void
    {
        $settings = new Settings('settings');

        $original = "sp\"ec'al 😀";
        $settings->saveSetting('special_value', $original);

        $this->assertSame($original, $settings->getSetting('special_value'));
        $this->assertSame(
            $original,
            \Lotgd\MySQL\Database::$settings_table['special_value'] ?? null
        );
    }
}
