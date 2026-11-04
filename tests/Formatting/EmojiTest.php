<?php

declare(strict_types=1);

namespace Lotgd\Tests\Formatting;

use Lotgd\Sanitize;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sanitize::stripEmoji(): what the commentary_emoji modes remove from a chat
 * line, and what they leave.
 */
final class EmojiTest extends TestCase
{
    protected function tearDown(): void
    {
        Settings::setInstance(null);
    }

    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function emoji(): iterable
    {
        // text, pictographs only, everything
        yield 'a face' => ["hi \u{1F600}", 'hi ', 'hi '];
        yield 'a face with a skin tone' => ["wave \u{1F44B}\u{1F3FD}", 'wave ', 'wave '];
        yield 'a family joined by ZWJ' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}!", '!', '!'];
        yield 'a flag' => ["go \u{1F1E9}\u{1F1EA}", 'go ', 'go '];
        yield 'a subdivision flag' => ["\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}x", 'x', 'x'];
        yield 'the pirate flag' => ["\u{1F3F4}\u{200D}\u{2620}\u{FE0F}x", 'x', 'x'];
        yield 'a keycap keeps its digit' => ["1\u{FE0F}\u{20E3}", '1', '1'];
        yield 'a heart in emoji form' => ["\u{2764}\u{FE0F}", '', ''];
        yield 'a smiley symbol' => ["\u{263A}", '', ''];
        yield 'a watch' => ["\u{231A}", '', ''];
        yield 'copyright and trademark' => ['© ®™', '© ®™', ' '];
        yield 'an arrow' => ['a ↔ b', 'a ↔ b', 'a  b'];
        yield 'an arrow in emoji form' => ["a \u{2194}\u{FE0F} b", 'a  b', 'a  b'];
        yield 'a woman with a skin tone and a sign' => ["\u{1F469}\u{1F3FD}\u{200D}\u{2640}\u{FE0F}.", '.', '.'];
        yield 'a face as a hex reference' => ['hi &#x1F600;', 'hi ', 'hi '];
        yield 'a face as a decimal reference' => ['hi &#128512;', 'hi ', 'hi '];
        yield 'a heart as a named reference' => ['&hearts;!', '!', '!'];
        yield 'copyright as a reference' => ['&copy;', '&copy;', ''];
    }

    #[DataProvider('emoji')]
    public function testTheModesRemoveEmoji(string $text, string $pictographs, string $all): void
    {
        self::assertSame($text, Sanitize::stripEmoji($text, Sanitize::EMOJI_KEEP));
        self::assertSame($pictographs, Sanitize::stripEmoji($text, Sanitize::EMOJI_PICTOGRAPHS));
        self::assertSame($all, Sanitize::stripEmoji($text, Sanitize::EMOJI_ALL));
    }

    public function testLettersCodesAndTextEmoticonsStay(): void
    {
        $text = 'Grüße 漢字 Ελλάδα `bbold`b `2green :-) <3 ;)';

        self::assertSame($text, Sanitize::stripEmoji($text, Sanitize::EMOJI_PICTOGRAPHS));
        self::assertSame($text, Sanitize::stripEmoji($text, Sanitize::EMOJI_ALL));
    }

    public function testJoinersOutsideEmojiStay(): void
    {
        // Indic shaping asks for a joiner; there is no emoji in it.
        $text = "\u{0915}\u{094D}\u{200D}\u{0937}";

        self::assertSame($text, Sanitize::stripEmoji($text, Sanitize::EMOJI_PICTOGRAPHS));
        self::assertSame($text, Sanitize::stripEmoji($text, Sanitize::EMOJI_ALL));
    }

    public function testOtherReferencesStay(): void
    {
        $text = 'Tom &amp; Jerry &lt;3 &#65; &nbsp; &unknown;';

        self::assertSame($text, Sanitize::stripEmoji($text, Sanitize::EMOJI_ALL));
    }

    public function testThePreviewsGetTheSamePatternsForJavaScript(): void
    {
        self::assertSame([], Sanitize::emojiPatternsForScript(Sanitize::EMOJI_KEEP));
        foreach ([Sanitize::EMOJI_PICTOGRAPHS, Sanitize::EMOJI_ALL] as $mode) {
            $php = Sanitize::emojiPatterns($mode);
            $js = Sanitize::emojiPatternsForScript($mode);
            self::assertCount(count($php), $js);
            foreach ($js as $i => $pattern) {
                self::assertStringNotContainsString('\\x{', $pattern);
                self::assertSame($php[$i], str_replace('\\u{', '\\x{', $pattern));
            }
        }
    }

    public function testATextThatIsNotUtf8ComesBackUnchanged(): void
    {
        self::assertSame("a\xFF b", Sanitize::stripEmoji("a\xFF b", Sanitize::EMOJI_ALL));
    }

    public function testAnUnknownModeKeepsEverything(): void
    {
        self::assertSame("hi \u{1F600}", Sanitize::stripEmoji("hi \u{1F600}", 7));
    }

    public function testTheModeComesFromTheSettingAndDefaultsToKeep(): void
    {
        self::assertSame(Sanitize::EMOJI_KEEP, Sanitize::commentaryEmojiMode(), 'No settings: kept.');

        Settings::setInstance(new DummySettings([]));
        self::assertSame(Sanitize::EMOJI_KEEP, Sanitize::commentaryEmojiMode(), 'Not set: kept.');

        foreach ([1 => Sanitize::EMOJI_PICTOGRAPHS, 2 => Sanitize::EMOJI_ALL, 9 => Sanitize::EMOJI_KEEP, 0 => Sanitize::EMOJI_KEEP] as $value => $mode) {
            Settings::setInstance(new DummySettings(['commentary_emoji' => $value]));
            self::assertSame($mode, Sanitize::commentaryEmojiMode(), "commentary_emoji = $value");
        }
    }
}
