<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DataCache;
use Lotgd\Output;
use Lotgd\PageParts;
use Lotgd\Template;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * Equipment names reach the character stats as text, not as markup.
 *
 * The stat block is encoded with `appoencode(..., true)`, which turns colour
 * codes into spans and passes everything else through, so a weapon or armour
 * name containing a tag was rendered as that tag. Modules let players name
 * their weapon; every other place shows these names through `output()`, which
 * escapes them, and the sidebar has to agree.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PagePartsCharStatsEscapeTest extends TestCase
{
    private function renderCharStats(string $weapon, string $armor): string
    {
        global $session, $settings, $output, $companions;

        if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
            eval('namespace Lotgd\\Modules; class HookHandler { public static function hook($name, $data = [], $allowinactive = false, $only = false) { return $data; } }');
        }
        class_exists(Database::class);
        class_exists(DataCache::class);
        $output = new Output();
        Output::setInstance($output);
        $settings = new DummySettings([
            'usedatacache' => 0,
            'enabletranslation' => false,
        ]);
        $companions = [];
        // Just the rows: the stat values are what is under test, not the frame.
        Template::getInstance()->setTemplate([
            'statrow' => '{title}: {value}|',
            'statbuff' => '{title}: {value}|',
        ]);
        $session = [
            'loggedin' => true,
            'bufflist' => [],
            'user' => [
                'acctid' => 1,
                'name' => 'Hero',
                'race' => 'Human',
                'sex' => 0,
                'level' => 1,
                'alive' => 1,
                'hitpoints' => 10,
                'maxhitpoints' => 10,
                'experience' => 0,
                'strength' => 10,
                'dexterity' => 10,
                'intelligence' => 10,
                'constitution' => 10,
                'wisdom' => 10,
                'attack' => 1,
                'defense' => 1,
                'weapondmg' => 0,
                'armordef' => 0,
                'dragonkills' => 0,
                'turns' => 10,
                'playerfights' => 3,
                'spirits' => 0,
                'gold' => 0,
                'goldinbank' => 0,
                'gems' => 0,
                'hashorse' => 0,
                'superuser' => 0,
                'weapon' => $weapon,
                'armor' => $armor,
            ],
        ];

        return PageParts::charStats();
    }

    public function testATagInTheWeaponNameIsShownAsText(): void
    {
        $html = $this->renderCharStats('<script>alert(1)</script>', 'T-Shirt');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testATagInTheArmorNameIsShownAsText(): void
    {
        $html = $this->renderCharStats('Fists', '<img src=x onerror=alert(1)>');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    /**
     * Colour codes are game markup, not HTML, and keep working.
     */
    public function testColourCodesInEquipmentNamesStillRender(): void
    {
        $html = $this->renderCharStats('`$Zangetsu`0', '`!Shihakushō`0');

        self::assertStringContainsString("<span class='colLtRed'>Zangetsu</span>", $html);
        self::assertStringContainsString("<span class='colLtBlue'>Shihakushō</span>", $html);
    }
}
