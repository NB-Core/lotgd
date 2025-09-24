<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use ErrorException;
use Lotgd\Output;
use Lotgd\Substitute;
use PHPUnit\Framework\TestCase;

final class OutputNotlArrayArgumentTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $badguy;

        $session = [
            'user' => [
                'name' => 'Tester',
                'weapon' => 'Dagger',
                'armor' => 'Cloak',
                'sex' => 0,
                'prefs' => ['ihavenocheer' => 0],
                'superuser' => 0,
            ],
        ];

        $badguy = [
            'creatureweapon' => 'Club',
            'creaturename' => 'Bandit',
            'diddamage' => 0,
        ];
    }

    protected function tearDown(): void
    {
        global $session, $badguy;

        unset($session, $badguy);
    }

    public function testListArgumentArrayIsFormattedWithoutWarning(): void
    {
        $output = new Output();
        $parts  = Substitute::applyArray('`5Hello {badguy}`0');

        $handler = static function (int $severity, string $message): bool {
            if ($severity === E_WARNING) {
                throw new ErrorException($message, 0, $severity);
            }

            return false;
        };

        set_error_handler($handler, E_WARNING);

        try {
            $output->outputNotl('%s', $parts);
        } finally {
            restore_error_handler();
        }

        $result = $output->getRawOutput();

        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('Bandit', $result);
    }
}

