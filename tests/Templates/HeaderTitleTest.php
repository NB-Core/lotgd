<?php

declare(strict_types=1);

namespace Lotgd\Tests\Templates;

use Lotgd\Page\Header;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The title the two head paths share.
 *
 * They did not share it: pageHeader() ran the translated title through
 * Sanitize::sanitize() and popupHeader() did not, so the same title came out
 * differently depending on which window it was headed for -- a popup's showed
 * the colour code as text. Two copies of four lines, one of which had been
 * changed. Reported by Copilot.
 *
 * One method now, which is the only form of this that cannot drift again, and
 * the reason to test the method rather than to assert that both callers call
 * it: driving popupHeader() itself would need Template, Nav, the module hooks
 * and a session, and would still be asserting on the same four lines.
 *
 * In its own process for the reason given in HeadPlaceholdersAreEscapedTest:
 * tests/DiagnosticsPageTest.php eval()s a stand-in Lotgd\Page\Header with
 * autoloading disabled, and alphabetically it gets there first.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeaderTitleTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::resetDoctrineConnection();
    }

    protected function tearDown(): void
    {
        \Lotgd\MySQL\Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    /**
     * The colour codes come out, which is what popup titles were keeping.
     *
     * A title is one of the places the game writes them -- `4 is red -- and
     * what a browser does with a backtick and a digit in a <title> is print
     * them.
     */
    public function testAColourCodeDoesNotSurviveIntoTheTitle(): void
    {
        self::assertSame('Ye Olde Poste', Header::headerTitle(['Ye Olde `4Poste']));
    }

    /**
     * The arguments are a sprintf call, not a string.
     *
     * Both callers hand it func_get_args(), so a title assembled from values --
     * which is most of them -- has to come out assembled.
     */
    public function testTheTitleIsFormattedFromItsArguments(): void
    {
        self::assertSame('Clan Membership for Reds', Header::headerTitle(['Clan Membership for %s', 'Reds']));
    }

    /**
     * No arguments is a real call: both headers are invoked bare in places.
     */
    public function testAnEmptyCallStillHasATitle(): void
    {
        self::assertSame('Legend of the Green Dragon', Header::headerTitle([]));
    }

    /**
     * The entities a title legitimately carries are left alone.
     *
     * pages/clan/detail.php:121 asks for "Clan Membership for %s &lt;%s&gt;",
     * so the angle brackets a player sees are written as entities on purpose.
     * This is the measurement behind not escaping {title} in the head: escaping
     * would print `&lt;` to the reader. It is pinned here so that the decision
     * is one someone has to argue with rather than one they can undo by
     * accident.
     */
    public function testAnEntityInATitleIsNotTouched(): void
    {
        self::assertSame(
            'Clan Membership for Reds &lt;RED&gt;',
            Header::headerTitle(['Clan Membership for %s &lt;%s&gt;', 'Reds', 'RED'])
        );
    }
}
