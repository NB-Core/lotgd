<?php

declare(strict_types=1);

namespace Lotgd\Tests\Formatting;

use Lotgd\Output;
use Lotgd\Sanitize;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bold and italic in a player's text, closed before the text ends.
 *
 * Output::appoencode() toggles <b> and <i> with one state for the whole page,
 * so a text that opens `b and never closes it bolds everything after it. The
 * helpers rewrite the codes so the text closes what it opens, or neutralise
 * them where the game does not allow them.
 */
final class UserFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        Output::setInstance(new Output());
        Settings::setInstance(new DummySettings(['charset' => 'UTF-8']));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function balanced(): iterable
    {
        yield 'nothing to do' => ['plain `2green', 'plain `2green'];
        yield 'empty' => ['', ''];
        yield 'an open bold is closed' => ['`bA', '`bA`b'];
        yield 'two open codes close innermost first' => ['`bA `iB', '`bA `iB`i`b'];
        yield 'crossed codes are nested' => ['`b`ia`bb`i', '`b`ia`i`b`ib`i'];
        yield 'closed codes stay as written' => ['`bA`b `iB`i', '`bA`b `iB`i'];
        yield 'an escaped backtick is not a code' => ['``b stays', '``b stays'];
        yield 'colours pass through' => ['`b`4red`0 normal', '`b`4red`0 normal`b'];
        yield 'a lone closing code opens and closes' => ['A`b', 'A`b`b'];
        yield 'a trailing backtick cannot swallow the closer' => ['`bhello`', '`bhello```b'];
        yield 'a trailing backtick alone is made literal' => ['hello`', 'hello``'];
        yield 'an escaped backtick at the end stays' => ['`bhello``', '`bhello```b'];
    }

    #[DataProvider('balanced')]
    public function testFormattingIsBalanced(string $in, string $expected): void
    {
        self::assertSame($expected, Sanitize::balanceFormatting($in));
    }

    public function testBalancedTextLeavesThePageAsItWas(): void
    {
        $output = new Output();
        $html = $output->appoencode(Sanitize::balanceFormatting('`bbold `iboth')) . $output->appoencode('after');

        self::assertSame('<b>bold <i>both</i></b>after', $html);
    }

    public function testATrailingBacktickDoesNotLeaveBoldOpen(): void
    {
        $output = new Output();
        $html = $output->appoencode(Sanitize::balanceFormatting('`bhello`')) . $output->appoencode('after');

        self::assertSame('<b>hello`</b>after', $html);
    }

    public function testEscapingNeutralisesOnlyTheNamedCodes(): void
    {
        self::assertSame('``bA ``iB `2c ``c', Sanitize::escapeCodes('`bA `iB `2c ``c', 'bi'));
    }

    public function testUserFormattingBalancesWhenAllowedAndEscapesOtherwise(): void
    {
        self::assertSame('`bA`b', Sanitize::userFormatting('`bA', true));
        self::assertSame('``bA', Sanitize::userFormatting('`bA', false));
    }

    public function testCommentSanitizeCanKeepBoldAndItalic(): void
    {
        self::assertSame('``cx', Sanitize::commentSanitize('`cx'));
        self::assertSame('`bx``c', Sanitize::commentSanitize('`bx`c', Sanitize::USER_FORMATTING));
    }

    public function testEachAreaHasItsOwnSetting(): void
    {
        self::assertFalse(Sanitize::formattingAllowed('commentary'), 'Chat stays as it was by default.');
        self::assertFalse(Sanitize::formattingAllowed('bio'));
        self::assertTrue(Sanitize::formattingAllowed('mail'), 'Mail rendered the codes before; now it closes them.');
        self::assertTrue(Sanitize::formattingAllowed('clan'));

        Settings::setInstance(new DummySettings(['formatting_commentary' => 1, 'formatting_mail' => 0]));
        self::assertTrue(Sanitize::formattingAllowed('commentary'));
        self::assertFalse(Sanitize::formattingAllowed('mail'));
    }

    public function testThePageClosesWhatItLeftOpen(): void
    {
        $output = new Output();
        $output->outputNotl('`b`c`4bold');

        $html = $output->getOutput();

        self::assertStringEndsWith("bold\n</span></div></b>", $html);
    }
}
