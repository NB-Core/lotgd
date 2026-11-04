<?php

declare(strict_types=1);

namespace Lotgd\Tests\Formatting;

use Lotgd\Commentary;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A chat line may bold and italicise when the game allows it, and closes what
 * it opens so the next line (and the rest of the page) is not bold.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommentaryFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $output;
        $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'superuser' => 0]];
        $output = new Output();
        Output::setInstance($output);
        $_SERVER['REQUEST_URI'] = '/';
    }

    public function testAllowedFormattingIsClosedWithinTheLine(): void
    {
        Settings::setInstance(new DummySettings(['charset' => 'UTF-8', 'formatting_commentary' => 1]));

        $line = Commentary::renderCommentLine($this->row(':`bshouts `iloudly'), false);

        self::assertStringContainsString('`bshouts `iloudly`i`b', $line);
        $html = (new Output())->appoencode($line);
        self::assertSame(substr_count($html, '<b>'), substr_count($html, '</b>'));
        self::assertSame(substr_count($html, '<i>'), substr_count($html, '</i>'));
    }

    public function testWithFormattingOffTheCodesStayLiteral(): void
    {
        Settings::setInstance(new DummySettings(['charset' => 'UTF-8', 'formatting_commentary' => 0]));

        $line = Commentary::renderCommentLine($this->row(':`bshouts'), false);

        self::assertStringContainsString('``bshouts', $line);
        self::assertStringNotContainsString('<b>', (new Output())->appoencode($line));
    }

    public function testOtherCodesStayNeutralisedWhenFormattingIsOn(): void
    {
        Settings::setInstance(new DummySettings(['charset' => 'UTF-8', 'formatting_commentary' => 1]));

        $line = Commentary::renderCommentLine($this->row(':`cmiddle'), false);

        self::assertStringContainsString('``cmiddle', $line);
    }

    public function testEmojiStayUnlessTheGameRemovesThem(): void
    {
        $comment = ":waves \u{1F44B}\u{1F3FD} with \u{2122}";

        Settings::setInstance(new DummySettings(['charset' => 'UTF-8']));
        self::assertStringContainsString("waves \u{1F44B}\u{1F3FD} with &trade;", Commentary::renderCommentLine($this->row($comment), false), 'Off by default.');

        Settings::setInstance(new DummySettings(['charset' => 'UTF-8', 'commentary_emoji' => 1]));
        $line = Commentary::renderCommentLine($this->row($comment), false);
        self::assertStringContainsString('waves  with &trade;', $line, 'Pictographs go, the trademark stays.');

        Settings::setInstance(new DummySettings(['charset' => 'UTF-8', 'commentary_emoji' => 2, 'formatting_commentary' => 1]));
        $line = Commentary::renderCommentLine($this->row(":`b\u{1F600}bold"), false);
        self::assertStringContainsString('`bbold`b', $line, 'Removed before bold is balanced.');
        self::assertStringNotContainsString("\u{1F600}", $line);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $comment): array
    {
        return [
            'acctid' => 2, 'name' => 'Talker', 'clanrank' => 0, 'clanshort' => '', 'superuser' => 0,
            'comment' => $comment, 'commentid' => 1, 'postdate' => date('Y-m-d H:i:s'), 'section' => 'village',
        ];
    }
}
