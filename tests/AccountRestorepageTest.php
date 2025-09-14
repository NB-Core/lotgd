<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class AccountRestorepageTest extends TestCase
{
    public function testCreatePhpSetsDefaultRestorepage(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/create.php');
        $this->assertMatchesRegularExpression('/restorepage.*village\.php/s', $content);
    }

    public function testInstallerSetsDefaultRestorepage(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/install/lib/Installer.php');
        $this->assertMatchesRegularExpression('/restorepage.*village\.php/s', $content);
    }

    public function testTablesDefaultRestorepage(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/install/data/tables.php');
        $this->assertMatchesRegularExpression("/'restorepage' => array\\(.*'default' => 'village.php'/s", $content);
    }
}
