<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Battle;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class BattleRoundSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        global $enemycounter, $session;

        $settings = new DummySettings([
            'forestcreaturebar' => 2,
        ]);
        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;

        $session = [
            'user' => [
                'alive' => true,
                'name' => 'Tester',
                'level' => 5,
                'dragonkills' => 0,
                'hitpoints' => 40,
                'maxhitpoints' => 50,
                'prefs' => [
                    'forestcreaturebar' => 2,
                ],
            ],
        ];
        $enemycounter = 1;
        Output::getInstance()->resetOutput();
    }

    public function testNormalSingleEnemyRoundShowsSummary(): void
    {
        Battle::showRoundSummary([$this->enemy('Goblin', 7, 10, true)], true);

        $output = Output::getInstance()->getRawOutput();

        self::assertStringContainsString('End of Round', $output);
        self::assertStringContainsString('Goblin', $output);
        self::assertStringContainsString('(7/10)', $output);
    }

    public function testMultiEnemyRoundShowsEverySurvivorHpAndHealthBars(): void
    {
        global $enemycounter;

        $enemycounter = 2;
        Battle::showRoundSummary([
            $this->enemy('Goblin Scout', 7, 10, true),
            $this->enemy('Orc Guard', 12, 15),
        ], true);

        $output = Output::getInstance()->getRawOutput();

        self::assertStringContainsString('End of Round', $output);
        self::assertStringContainsString('Goblin Scout', $output);
        self::assertStringContainsString('(7/10)', $output);
        self::assertStringContainsString('Orc Guard', $output);
        self::assertStringContainsString('(12/15)', $output);
        self::assertGreaterThanOrEqual(
            3,
            substr_count($output, "<div style='display: block;background-color:")
        );
    }

    public function testSummaryShowsRemainingEnemyAfterAnotherEnemyDies(): void
    {
        global $enemycounter;

        $enemycounter = 2;
        Battle::showRoundSummary([
            $this->enemy('Fallen Goblin', 0, 10),
            $this->enemy('Orc Survivor', 9, 15, true),
        ], true);

        $output = Output::getInstance()->getRawOutput();

        self::assertStringContainsString('End of Round', $output);
        self::assertStringContainsString('Orc Survivor', $output);
        self::assertStringContainsString('(9/15)', $output);
    }

    public function testTargetSelectionWithoutCombatDoesNotShowRoundSummary(): void
    {
        global $enemycounter;

        $enemycounter = 2;
        Battle::showRoundSummary([
            $this->enemy('Goblin Scout', 10, 10),
            $this->enemy('Selected Orc', 15, 15, true),
        ], false);

        self::assertStringNotContainsString(
            'End of Round',
            Output::getInstance()->getRawOutput()
        );
    }

    public function testInitialSearchWithoutSurpriseDoesNotShowRoundSummary(): void
    {
        $enemy = $this->enemy('Forest Goblin', 10, 10, true);
        $operation = 'search';
        $surprised = false;
        $combatRoundExecuted = false;

        if ($operation === 'fight' || $operation === 'run' || $surprised) {
            $combatRoundExecuted = true;
        }

        Battle::showRoundSummary([$enemy], $combatRoundExecuted);

        self::assertStringNotContainsString(
            'End of Round',
            Output::getInstance()->getRawOutput()
        );
    }

    /**
     * Build the minimum enemy state consumed by Battle::showEnemies().
     *
     * @return array<string, int|string|bool>
     */
    private function enemy(
        string $name,
        int $health,
        int $maxHealth,
        bool $isTarget = false
    ): array {
        return [
            'creaturename' => $name,
            'creaturehealth' => $health,
            'creaturemaxhealth' => $maxHealth,
            'creaturelevel' => 3,
            'istarget' => $isTarget,
            'dead' => $health <= 0,
        ];
    }
}
