<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

use Lotgd\Forms;
use PHPUnit\Framework\TestCase;

/**
 * The three control primitives, asked directly.
 *
 * They return strings and touch neither Output nor the database, so they are
 * tested here rather than through a rendered page. What a page does with them
 * is tests/Mail/MailReadActionBarTest's question; whether a module can reach
 * them is tests/Mail/MailReadActionsHookTest's.
 *
 * The defence against a malformed entry gets most of the room below, and that
 * is deliberate: these lists pass through module code, and the entries this
 * skips are the ones a module will get wrong.
 */
final class ActionBarTest extends TestCase
{
    public function testALinkCarriesItsClassAndEscapesBothHalves(): void
    {
        $html = Forms::linkButton("mail.php?a=1&b=2", "Tom & Jerry's", 'button mail-nav__link');

        self::assertSame(
            "<a href='mail.php?a=1&amp;b=2' class='button mail-nav__link'>Tom &amp; Jerry&#039;s</a>",
            $html
        );
    }

    /**
     * The single quote is the one that matters.
     *
     * Attributes here are single-quoted, so a label carrying an apostrophe
     * would close the attribute early if the escaper used ENT_COMPAT. Escape's
     * docblock says ENT_QUOTES is chosen for exactly this; asserting it means
     * a change there fails here rather than silently opening a hole.
     */
    public function testAnApostropheCannotCloseAnAttribute(): void
    {
        $html = Forms::linkButton("x.php", "it's", "cl'ass");

        self::assertStringNotContainsString("cl'ass", $html);
        self::assertStringContainsString('&#039;', $html);
    }

    public function testADisabledControlIsASpanThatSaysSo(): void
    {
        $html = Forms::disabledButton('< Previous', 'button mail-nav__link');

        self::assertSame(
            "<span class='button mail-nav__link' aria-disabled='true'>&lt; Previous</span>",
            $html
        );
    }

    public function testEachKindBecomesItsOwnElement(): void
    {
        $html = Forms::actionBar([
            ['kind' => 'disabled', 'label' => 'Previous'],
            ['kind' => 'link', 'url' => 'mail.php?op=read&id=9', 'label' => 'Next'],
        ]);

        self::assertStringContainsString("<span class='button mail-nav__link' aria-disabled='true'>Previous</span>", $html);
        self::assertStringContainsString("<a href='mail.php?op=read&amp;id=9'", $html);
        self::assertStringStartsWith("<div class='mail-nav'>", $html);
        self::assertStringEndsWith('</div>', $html);
    }

    public function testAPostingEntryCarriesItsConfirmationAndItsFields(): void
    {
        $html = Forms::actionBar([[
            'kind' => 'post',
            'url' => 'petition.php',
            'label' => 'Report',
            'confirm' => 'Really?',
            'fields' => ['abuse' => 'yes'],
        ]]);

        self::assertStringContainsString("<form action='petition.php' method='POST'", $html);
        self::assertStringContainsString("name='abuse' value='yes'", $html);
        self::assertStringContainsString('confirm(', $html);
    }

    /**
     * Controls are separated by a newline, and that is not cosmetic.
     *
     * Three legacy themes define no `.mail-nav`, so these controls stay inline
     * there -- and whitespace between inline elements is rendered as a space.
     * Joined with nothing they would touch. The bars this replaced emitted one
     * rawOutput() per control, each appending a newline, so this is also what
     * keeps the markup identical to what those themes already received.
     */
    public function testControlsAreSeparated(): void
    {
        $html = Forms::actionBar([
            ['kind' => 'link', 'url' => 'a.php', 'label' => 'A'],
            ['kind' => 'link', 'url' => 'b.php', 'label' => 'B'],
        ]);

        self::assertStringContainsString("</a>\n<a", $html);
    }

    /**
     * @return array<string, array{0: array<string,mixed>}>
     */
    public static function malformedEntries(): array
    {
        return [
            'no kind' => [['url' => 'x.php', 'label' => 'X']],
            'unknown kind' => [['kind' => 'iframe', 'url' => 'x.php', 'label' => 'X']],
            'no label' => [['kind' => 'link', 'url' => 'x.php']],
            'label is not a string' => [['kind' => 'link', 'url' => 'x.php', 'label' => ['X']]],
            'link with no url' => [['kind' => 'link', 'label' => 'X']],
            'link with an empty url' => [['kind' => 'link', 'url' => '', 'label' => 'X']],
            'post with no url' => [['kind' => 'post', 'label' => 'X']],
        ];
    }

    /**
     * A bad entry costs its own control and nothing else.
     *
     * Every case is paired with a good entry rather than asserted alone,
     * because "the malformed entry did not render" is also satisfied by a bar
     * that rendered nothing at all -- including one that threw. The survivor is
     * what separates skipping from falling over.
     *
     * @param array<string,mixed> $malformed
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedEntries')]
    public function testAMalformedEntryIsSkippedWithoutTakingTheRowWithIt(array $malformed): void
    {
        $html = Forms::actionBar([
            $malformed,
            ['kind' => 'link', 'url' => 'good.php', 'label' => 'Survivor'],
        ]);

        self::assertStringContainsString('Survivor', $html);
        self::assertSame(1, substr_count($html, '<a '), 'only the well-formed entry should render');
        self::assertStringNotContainsString('x.php', $html);
    }

    public function testAnEntryThatIsNotAnArrayIsSkipped(): void
    {
        $html = Forms::actionBar([
            'just a string',
            ['kind' => 'link', 'url' => 'good.php', 'label' => 'Survivor'],
        ]);

        self::assertStringContainsString('Survivor', $html);
        self::assertStringNotContainsString('just a string', $html);
    }

    /**
     * An empty bar is still a bar.
     *
     * Reachable only through the hook -- a module returning an empty array --
     * and the markup should stay well-formed when it happens rather than emit a
     * div containing stray whitespace.
     */
    public function testAnEmptyListRendersAnEmptyContainer(): void
    {
        self::assertSame("<div class='mail-nav'></div>", Forms::actionBar([]));
    }

    public function testAnEntryMayOverrideTheClass(): void
    {
        $html = Forms::actionBar([
            ['kind' => 'link', 'url' => 'x.php', 'label' => 'X', 'class' => 'hotmotd'],
        ]);

        self::assertStringContainsString("class='hotmotd'", $html);
    }

    public function testTheContainerClassIsEscaped(): void
    {
        $html = Forms::actionBar([], "mail-nav' onload='evil");

        self::assertStringNotContainsString("onload='evil", $html);
        self::assertStringContainsString('&#039;', $html);
    }
}
