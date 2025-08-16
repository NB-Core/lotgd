<?php

declare(strict_types=1);

namespace Lotgd\Forest;

class Outcomes
{
    public static string $lastWhere;

    public static function defeat($enemies, $where): void
    {
        self::$lastWhere = $where;
    }
}

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/forestoutcomes.php';

final class ForestDefeatLocationTest extends TestCase
{
    public function testArrayWhereIsTranslated(): void
    {
        \forestdefeat([], ['travelling to %s', 'Gotham']);
        $this->assertSame('travelling to Gotham', \Lotgd\Forest\Outcomes::$lastWhere);
    }
}
