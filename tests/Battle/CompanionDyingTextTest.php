<?php

declare(strict_types=1);

namespace Lotgd\Tests\Battle;

use Lotgd\Battle;
use Lotgd\Output;
use Lotgd\Template;
use Lotgd\Translator;
use PHPUnit\Framework\TestCase;

/**
 * How a companion's death is announced, pinned before it is moved.
 *
 * `Battle::reportCompanionMove()` had no tests at all, and this is the part of
 * it that `Buffs::activateBuffs()` now has to match: a damaging aura can kill a
 * companion since #1538, and Codex pointed out that the newly reachable branch
 * rendered the same `dyingtext` differently -- no `{companion}` substitution,
 * and the `battle` schema regardless of what the companion declares. A module's
 * text would come out with a literal placeholder in it and untranslated.
 *
 * So these cases exist to make the extraction that fixes it provably
 * behaviour-preserving for the path that already worked. They describe what
 * the method does today; if the extraction changed anything, they say so.
 */
final class CompanionDyingTextTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $badguy;

        $session = ['user' => ['name' => 'Hero', 'sex' => 0, 'weapon' => 'Sword', 'armor' => 'Mail']];
        $badguy = ['creaturename' => 'Goblin', 'creatureweapon' => 'Club', 'creaturehealth' => 10, 'dead' => false];
        Template::getInstance()->setTemplate([]);
        Output::getInstance()->resetOutput();
    }

    protected function tearDown(): void
    {
        global $session, $badguy;

        unset($session, $badguy);
        Translator::getInstance()->setSchema();
        Template::getInstance()->setTemplate([]);
    }

    /**
     * @param array<string,mixed> $companion
     */
    private function announce(array $companion): string
    {
        global $badguy;

        // 'heal' with no abilities takes none of the acting branches, so the
        // method falls straight through to the block that reports the death --
        // no damage roll, and therefore no randomness in the fixture.
        Battle::reportCompanionMove($badguy, $companion + [
            'name' => 'Wolf',
            'hitpoints' => 0,
            'maxhitpoints' => 100,
            'abilities' => [],
            'used' => false,
        ], 'heal');

        return Output::getInstance()->getRawOutput();
    }

    public function testTheCompanionsOwnDyingTextIsUsed(): void
    {
        self::assertStringContainsString('The Wolf falls silent.', $this->announce([
            'dyingtext' => 'The Wolf falls silent.',
        ]));
    }

    /**
     * `{companion}` is substituted, which is the half the aura path was missing.
     */
    public function testTheCompanionPlaceholderIsSubstituted(): void
    {
        $output = $this->announce(['dyingtext' => '{companion} breathes its last.']);

        self::assertStringContainsString('Wolf breathes its last.', $output);
        self::assertStringNotContainsString('{companion}', $output);
    }

    /**
     * A companion with no text of its own still gets a farewell.
     */
    public function testACompanionWithoutADyingTextGetsTheDefaultOne(): void
    {
        self::assertStringContainsString('catches his last breath', $this->announce([]));
    }

    /**
     * An empty string counts as no text, not as a silent death.
     */
    public function testAnEmptyDyingTextFallsBackToTheDefault(): void
    {
        self::assertStringContainsString('catches his last breath', $this->announce(['dyingtext' => '']));
    }

    /**
     * The companion's own schema is in force while its text is rendered.
     *
     * The half of Codex's finding that the case below does not reach: restoring
     * the schema afterwards says nothing about which one was used, and the
     * mutation that forces "battle" regardless survived until this existed. A
     * module declaring a schema had its farewell looked up in the wrong table,
     * which is invisible in English and empty in every other language.
     *
     * Observed by standing a recorder in for the output singleton, because the
     * schema is set and put back inside one call -- there is no moment outside
     * it at which the answer is still true.
     */
    public function testTheCompanionsOwnSchemaIsInForceWhileItIsRendered(): void
    {
        $recorder = new class extends Output {
            /** @var list<string> */
            public array $schemas = [];

            // No declared parameters, matching the parent: Output::output()
            // and outputNotl() take their arguments through func_get_args(),
            // and declaring any here is an incompatible signature.
            public function output()
            {
                $this->schemas[] = Translator::getInstance()->getSchema();
            }

            public function outputNotl()
            {
            }
        };

        Output::setInstance($recorder);

        try {
            Battle::announceCompanionDeath([
                'name' => 'Wolf',
                'dyingtext' => 'Goodbye.',
                'schema' => 'mymodule',
            ]);
        } finally {
            Output::setInstance(null);
        }

        self::assertSame(['mymodule'], $recorder->schemas);
    }

    /**
     * And a companion without one falls back to the battle schema.
     */
    public function testACompanionWithoutASchemaIsRenderedInTheBattleSchema(): void
    {
        $recorder = new class extends Output {
            /** @var list<string> */
            public array $schemas = [];

            // No declared parameters, matching the parent: Output::output()
            // and outputNotl() take their arguments through func_get_args(),
            // and declaring any here is an incompatible signature.
            public function output()
            {
                $this->schemas[] = Translator::getInstance()->getSchema();
            }

            public function outputNotl()
            {
            }
        };

        Output::setInstance($recorder);

        try {
            Battle::announceCompanionDeath(['name' => 'Wolf', 'dyingtext' => 'Goodbye.']);
        } finally {
            Output::setInstance(null);
        }

        self::assertSame(['battle'], $recorder->schemas);
    }

    /**
     * The schema is put back afterwards, whichever one was used.
     *
     * The method switches to the companion's schema to translate its text and
     * has to hand the translator back as it found it; a leak here would send
     * every later lookup on the page to the wrong table.
     */
    public function testTheTranslatorSchemaIsRestored(): void
    {
        $before = Translator::getInstance()->getSchema();

        $this->announce(['dyingtext' => 'Goodbye.', 'schema' => 'mymodule']);

        self::assertSame($before, Translator::getInstance()->getSchema());
    }
}
