<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class DoctrineWrapperTest extends TestCase
{
    private string $vendor;
    private string $backup;

    protected function setUp(): void
    {
        $this->vendor = __DIR__ . '/../vendor/bin/doctrine-migrations';
        $this->backup = $this->vendor . '.bak';
        rename($this->vendor, $this->backup);

        $stub = "#!/usr/bin/env php\n<?php echo json_encode(\$_SERVER['argv']);";
        file_put_contents($this->vendor, $stub);
        chmod($this->vendor, 0755);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->backup)) {
            rename($this->backup, $this->vendor);
        }
    }

    public function testDefaultConfigArgsAreAppended(): void
    {
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/doctrine') . ' status';
        $output = shell_exec($cmd);
        $args = json_decode((string) $output, true);

        $this->assertContains('--configuration=src/Lotgd/Config/migrations.php', $args);
        $this->assertContains('--db-configuration=src/Lotgd/Config/migrations-db.php', $args);
    }

    public function testProvidedConfigArgsAreNotDuplicated(): void
    {
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/doctrine') .
            ' status --configuration=foo.php --db-configuration=bar.php';
        $output = shell_exec($cmd);
        $args = json_decode((string) $output, true);

        $this->assertContains('--configuration=foo.php', $args);
        $this->assertContains('--db-configuration=bar.php', $args);
        $this->assertNotContains('--configuration=src/Lotgd/Config/migrations.php', $args);
        $this->assertNotContains('--db-configuration=src/Lotgd/Config/migrations-db.php', $args);
    }
}
