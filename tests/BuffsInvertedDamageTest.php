<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Buffs;
use PHPUnit\Framework\TestCase;

final class BuffsInvertedDamageTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $badguy, $count;
        $session = [];
        $badguy = [];
        $count = 0;
    }

    public function testInvertedDamageRangeDoesNotThrow(): void
    {
        global $session, $badguy, $count;

        $session['bufflist'] = [
            'test' => [
                'schema' => '',
                'minioncount' => 1,
                'maxbadguydamage' => 10,
                'minbadguydamage' => 20,
            ],
        ];

        $badguy = [
            'creaturehealth' => 100,
            'istarget' => true,
            'dead' => false,
        ];
        $count = 0;

        Buffs::activateBuffs('roundstart');

        $this->assertGreaterThanOrEqual(80, $badguy['creaturehealth']);
        $this->assertLessThanOrEqual(90, $badguy['creaturehealth']);
    }
}
