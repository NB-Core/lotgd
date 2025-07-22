<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PlayerFunctions;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class PlayerFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $session, $temp_user_stats;
        $settings = new DummySettings([
            'moneydecimalpoint' => '.',
            'moneythousandssep' => ',',
        ]);
        $session = ['user' => []];
        $temp_user_stats = ['add' => [], 'is_suspended' => false];
    }

    public function testCheckTempStatUsesDefaultFormatting(): void
    {
        global $session;
        $session['user']['strength'] = 1000.0;
        apply_temp_stat('strength', 10.4);
        $result = PlayerFunctions::checkTempStat('strength', 1);
        $this->assertSame(' `&(1,000.0`@+10.4`&)', $result);
    }

    public function testCheckTempStatHonorsCustomLocale(): void
    {
        $this->setCustomLocale(',', '.');
        global $session;
        $session['user']['strength'] = 1000.0;
        apply_temp_stat('strength', 10.4);
        $result = PlayerFunctions::checkTempStat('strength', 1);
        $this->assertSame(' `&(1.000,0`@+10,4`&)', $result);
    }

    private function setCustomLocale(string $decimal, string $thousand): void
    {
        global $settings;
        $settings = new DummySettings([
            'moneydecimalpoint' => $decimal,
            'moneythousandssep' => $thousand,
        ]);
    }
}
