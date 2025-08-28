<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Legacy\LegacySql;
use PHPUnit\Framework\TestCase;

final class LegacySqlTest extends TestCase
{
    public function testFilterStatementsRunsNewerVersions(): void
    {
        $statements = [
            '0.9' => ['a'],
            '1.0' => ['b'],
            '1.1' => ['c'],
        ];

        self::assertSame(['b', 'c'], LegacySql::filterStatements($statements, '0.9'));
        self::assertSame(['c'], LegacySql::filterStatements($statements, '1.0'));
        self::assertSame([], LegacySql::filterStatements($statements, '1.1'));
    }

    public function testFilterStatementsHandlesMissingVersion(): void
    {
        $statements = [
            '1.0.0' => ['a'],
            '1.0.1' => ['b'],
        ];

        self::assertSame(['a', 'b'], LegacySql::filterStatements($statements, '0.9'));
    }
}
