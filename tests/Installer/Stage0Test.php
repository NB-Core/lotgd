<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use PHPUnit\Framework\TestCase;

final class Stage0Test extends TestCase
{
    public function testInstallerOutputsDefaultFavicon(): void
    {
        $root = dirname(__DIR__, 2);
        $config = $root . '/dbconnect.php';
        $backup = $config . '.bak';

        if (file_exists($config)) {
            rename($config, $backup);
        }

        $cmd    = sprintf('cd %s && php installer.php', escapeshellarg($root));
        $output = shell_exec($cmd);

        if (file_exists($backup)) {
            rename($backup, $config);
        }

        $this->assertIsString($output);
        $this->assertStringContainsString('/images/favicon/favicon.ico', $output);
    }
}
