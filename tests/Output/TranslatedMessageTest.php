<?php

declare(strict_types=1);

namespace Lotgd\Tests\Output;

use Lotgd\Buffs;
use Lotgd\Output;
use Lotgd\Substitute;
use Lotgd\Template;
use Lotgd\Translator;
use PHPUnit\Framework\TestCase;

/**
 * A message built from game text is translated before its arguments go in.
 *
 * Buff, companion and shop messages reach Output::output() as a format and
 * its arguments (Substitute::applyArray()) or as a plain text. Passing them
 * as output('%s', $msg) handed the translator only "%s", so the translations
 * in the table were never used.
 */
final class TranslatedMessageTest extends TestCase
{
    private const NAMESPACE = 'translated-message-test';

    protected function setUp(): void
    {
        global $session, $badguy, $count;

        Translator::resetState();
        Translator::tlschema(self::NAMESPACE);
        Output::getInstance()->resetOutput();
        Template::getInstance()->setTemplate([]);
        $session = [
            'user' => [
                'maxhitpoints' => 100,
                'hitpoints' => 100,
                'name' => 'Hero',
                'weapon' => 'Sword',
                'armor' => 'Mail',
                'sex' => 0,
            ],
            'bufflist' => [],
        ];
        $badguy = ['istarget' => false, 'creaturename' => 'Goblin', 'creatureweapon' => 'Club'];
        $count = 0;
    }

    protected function tearDown(): void
    {
        global $session, $badguy, $count;

        unset($session, $badguy, $count);
        Translator::resetState();
        Output::getInstance()->resetOutput();
        Template::getInstance()->setTemplate([]);
    }

    /**
     * @param array<string, string> $table
     */
    private function translations(array $table, string $namespace = self::NAMESPACE): void
    {
        $property = new \ReflectionProperty(Translator::class, 'translation_table');
        $property->setValue(null, [$namespace => $table]);
    }

    public function testAFormatWithArgumentsIsTranslatedBeforeTheArgumentsGoIn(): void
    {
        $this->translations(['`5%s strikes hard!`0`n' => '`5%s schlaegt hart zu!`0`n']);

        Output::getInstance()->output(Substitute::applyArray('`5{goodguy} strikes hard!`0`n'));

        $html = Output::getInstance()->getOutput();
        self::assertStringContainsString('schlaegt hart zu!', $html);
        self::assertStringContainsString('Hero', $html);
    }

    public function testABuffRoundMessageUsesItsTranslation(): void
    {
        global $session;

        // Buffs translate in the namespace "buffs" unless a buff names its own.
        $this->translations(['`5%s glows with power!`0`n' => '`5%s gluehen vor Kraft!`0`n'], 'buffs');
        $session['bufflist']['glow'] = [
            'atkmod' => 1.5,
            'rounds' => 3,
            'roundmsg' => '{goodguy} glows with power!',
            'schema' => false,
        ];

        Buffs::activateBuffs('offense');

        $html = Output::getInstance()->getOutput();
        self::assertStringContainsString('gluehen vor Kraft!', $html);
        self::assertStringNotContainsString('glows with power', $html);
    }

    public function testAPlainGameTextIsTranslated(): void
    {
        $this->translations(['`7The smith looks you over.`0' => '`7Der Schmied mustert dich.`0']);

        Output::getInstance()->output('`7The smith looks you over.`0');

        self::assertStringContainsString('Der Schmied mustert dich.', Output::getInstance()->getOutput());
    }

    /**
     * A text a module left unset arrives as null: shown as nothing, no
     * TypeError from the translator, which takes strings only.
     */
    public function testAMissingTextIsShownAsNothing(): void
    {
        $this->translations([]);

        Output::getInstance()->output(null);
        Output::getInstance()->output(42);

        self::assertStringContainsString('42', Output::getInstance()->getOutput());
    }
}
