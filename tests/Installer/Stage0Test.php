<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Tests\Support\RootDbConnect;
use PHPUnit\Framework\TestCase;

final class Stage0Test extends TestCase
{
    private string $config;
    private ?RootDbConnect $dbconnect = null;

    protected function setUp(): void
    {
        $this->config = RootDbConnect::path();
        $this->dbconnect = RootDbConnect::takeOver();
    }

    protected function tearDown(): void
    {
        $this->dbconnect?->restore();
    }

    public function testInstallerOutputsDefaultFavicon(): void
    {
        $root   = dirname(__DIR__, 2);
        $cmd    = sprintf('cd %s && %s installer.php', escapeshellarg($root), escapeshellarg(PHP_BINARY));
        $output = shell_exec($cmd);

        $this->assertIsString($output);
        $this->assertStringContainsString('/images/favicon/favicon.ico', $output);
    }
}
