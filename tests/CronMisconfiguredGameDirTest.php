<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class CronMisconfiguredGameDirTest extends TestCase
{
    public function testMisconfiguredGameDirTriggersEmail(): void
    {
        $count = require __DIR__ . '/cron_misconfigured_game_dir.php';
        $this->assertSame(1, $count);
    }
}
