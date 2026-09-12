<?php

declare(strict_types=1);

namespace Lotgd\Tests\Training;

use Lotgd\Battle;
use Lotgd\CreateString;
use Lotgd\Output;
use Lotgd\PlayerFunctions;
use PHPUnit\Framework\TestCase;

/**
 * When the levelled companions are written back to the session matters as much
 * as that they are written back at all.
 *
 * train.php suspends the companions before every training fight
 * (`Battle::suspendCompanions("allowintrain")`) and lifts the suspension after
 * it. The lifting writes the `$companions` global and nothing else -- see
 * `Battle::unsuspendCompanions()`, whose last line is `$companions =
 * $newcompanions;` -- so anything serialised into the session before that point
 * carries `suspended => true` into the player's next battle, where the
 * companions then sit the fight out.
 *
 * This never mattered while the level-up result was discarded. It began to
 * matter the moment it was kept, which is why the write lives after the
 * unsuspension rather than beside the arithmetic. Reported by Codex on #1531.
 */
final class CompanionSuspensionPersistenceTest extends TestCase
{
    /** The serialised shape of a suspended companion. */
    private const SUSPENDED_MARKER = 's:9:"suspended";b:1';

    protected function setUp(): void
    {
        global $companions, $session;

        $session = ['user' => ['name' => 'Hero', 'sex' => 0]];
        $companions = [
            'Rex' => [
                'name' => 'Rex',
                'attack' => 10,
                'attackperlevel' => 2,
                'maxhitpoints' => 40,
                'maxhitpointsperlevel' => 5,
                'hitpoints' => 12,
                'allowintrain' => false,
            ],
        ];
        Output::getInstance()->resetOutput();
    }

    protected function tearDown(): void
    {
        global $companions, $session;

        unset($companions, $session);
    }

    /**
     * Level the companions the way train.php's victory block does.
     */
    private function levelUpInPlace(): void
    {
        global $companions;

        foreach ($companions as $name => $companion) {
            $companions[$name] = PlayerFunctions::levelUpCompanion($companion);
        }
    }

    /**
     * The positive control, and the reason the ordering is not a matter of
     * taste: persisting while the suspension is still in force writes it into
     * the session.
     */
    public function testPersistingBeforeTheUnsuspensionWouldFreezeIt(): void
    {
        global $companions;

        Battle::suspendCompanions('allowintrain');

        self::assertTrue($companions['Rex']['suspended'], 'precondition: the fight suspended them');

        $this->levelUpInPlace();

        self::assertStringContainsString(
            self::SUSPENDED_MARKER,
            CreateString::run($companions),
            'a write here carries the suspension into the next battle'
        );
    }

    /**
     * And after it, the array is what the player will actually fight with.
     */
    public function testPersistingAfterTheUnsuspensionIsClean(): void
    {
        global $companions;

        Battle::suspendCompanions('allowintrain');
        $this->levelUpInPlace();
        Battle::unsuspendCompanions('allowintrain');

        self::assertStringNotContainsString(
            self::SUSPENDED_MARKER,
            CreateString::run($companions),
            'no suspension may reach the session'
        );
    }

    /**
     * The level-up still reaches the session through that later write -- which
     * is the whole point of moving it rather than dropping it.
     */
    public function testTheLevelUpSurvivesTheUnsuspension(): void
    {
        global $companions;

        Battle::suspendCompanions('allowintrain');
        $this->levelUpInPlace();
        Battle::unsuspendCompanions('allowintrain');

        $restored = unserialize(CreateString::run($companions));

        self::assertSame(12, $restored['Rex']['attack'], '10 + 2');
        self::assertSame(45, $restored['Rex']['maxhitpoints'], '40 + 5');
        self::assertSame(45, $restored['Rex']['hitpoints'], 'and healed to the new maximum');
    }

    /**
     * train.php writes the session once, after the unsuspension.
     *
     * Checked against the source because train.php is a top-level script
     * needing a session, a database and a rendered header before it reaches any
     * of this. The ordering above is covered by executing cases; this one
     * guards that the page is wired in that order, which is the part that was
     * wrong.
     */
    public function testTrainPhpWritesTheSessionAfterTheUnsuspension(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/train.php');

        $unsuspend = strpos($source, 'Battle::unsuspendCompanions(');
        $persist = strpos($source, "\$session['user']['companions'] = CreateString::run(\$companions);");

        self::assertNotFalse($unsuspend, 'the unsuspension should be findable');
        self::assertNotFalse($persist, 'the session write should be findable');
        self::assertGreaterThan($unsuspend, $persist, 'the write must come after the unsuspension');

        self::assertSame(
            1,
            substr_count($source, "\$session['user']['companions'] ="),
            'exactly one write, so no earlier one can freeze the suspension'
        );
    }
}
