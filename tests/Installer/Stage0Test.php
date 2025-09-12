<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use PHPUnit\Framework\TestCase;

final class Stage0Test extends TestCase
{
    private string $config;
    private string $backup;

    protected function setUp(): void
    {
        $root          = dirname(__DIR__, 2);
        $this->config  = $root . '/dbconnect.php';
        $this->backup  = $this->config . '.bak';

        if (file_exists($this->config)) {
            rename($this->config, $this->backup);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->backup)) {
            rename($this->backup, $this->config);
        } elseif (file_exists($this->config)) {
            unlink($this->config);
        }
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
