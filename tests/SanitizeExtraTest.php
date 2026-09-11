<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Output;
use Lotgd\Sanitize;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class SanitizeExtraTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings;
        Output::setInstance(new Output());
        $settings = new DummySettings(['charset' => 'UTF-8']);
    }

    protected function tearDown(): void
    {
        global $settings;
        $settings = null;
    }

    public function testNewlineSanitize(): void
    {
        $this->assertSame('HelloWorld', Sanitize::newlineSanitize("Hello`nWorld"));
    }

    public function testStripAllColorCodes(): void
    {
        $this->assertSame('HelloWorld', Sanitize::stripAllColorCodes('Hello`xWorld'));
    }

    /**
     * The old name stays available for modules; it must keep delegating to the
     * same implementation rather than drifting into a second one.
     */
    public function testFullSanitizeRemainsAnAliasOfStripAllColorCodes(): void
    {
        foreach (['Hello`xWorld', '', '`b`iBold`i`b', 'plain text'] as $input) {
            $this->assertSame(
                Sanitize::stripAllColorCodes($input),
                Sanitize::fullSanitize($input)
            );
        }
    }

    /**
     * Guards the misunderstanding that gave the method its old name: it removes
     * colour markup and nothing else. Quotes survive, so a value that went
     * through it is not safe to interpolate into SQL.
     */
    public function testStripAllColorCodesDoesNotEscapeQuotes(): void
    {
        $this->assertSame("O'Brien", Sanitize::stripAllColorCodes("O'Brien"));
        $this->assertSame("' OR 1=1 --", Sanitize::stripAllColorCodes("`4' OR 1=1 --"));
    }

    public function testCmdSanitize(): void
    {
        $this->assertSame('page.php?op=foo', Sanitize::cmdSanitize('page.php?op=foo&c=1'));
    }

    public function testComscrollSanitize(): void
    {
        $this->assertSame('page.php?op=foo', Sanitize::comscrollSanitize('page.php?op=foo&comscroll=2'));
    }

    public function testTranslatorUriAndPage(): void
    {
        $uri = 'page.php?op=foo&c=1&refresh=1';
        $clean = Sanitize::translatorUri($uri);
        $this->assertSame('page.php?op=foo', $clean);
        $this->assertSame('page.php', Sanitize::translatorPage($clean));
    }

    public function testTranslatorUriMaxLength(): void
    {
        $uri = 'page.php?op=' . str_repeat('a', 500);
        $clean = Sanitize::translatorUri($uri);
        $this->assertLessThanOrEqual(Sanitize::URI_MAX_LENGTH, strlen($clean));
    }

    public function testTranslatorUriStripsMaliciousCharacters(): void
    {
        $uri = "pa'ge.php\"`;;?op=f'o;o\"`;";
        $clean = Sanitize::translatorUri($uri);
        $this->assertSame('page.php?op=foo', $clean);
        $this->assertStringNotContainsString("'", $clean);
        $this->assertStringNotContainsString('"', $clean);
        $this->assertStringNotContainsString('`', $clean);
        $this->assertStringNotContainsString(';', $clean);
    }

    public function testTranslatorPageStripsMaliciousCharacters(): void
    {
        $uri = "pa'ge.php\"`;;?op=f'o;o\"`;";
        $page = Sanitize::translatorPage($uri);
        $this->assertSame('page.php', $page);
        $this->assertStringNotContainsString("'", $page);
        $this->assertStringNotContainsString('"', $page);
        $this->assertStringNotContainsString('`', $page);
        $this->assertStringNotContainsString(';', $page);
    }

    public function testModulenameSanitize(): void
    {
        $this->assertSame('ModuleName', Sanitize::modulenameSanitize('Module!Name'));
    }

    public function testStripslashesArray(): void
    {
        $arr = ['a\\b', ['c\\d']];
        $expected = ['ab', ['cd']];
        $this->assertSame($expected, Sanitize::stripslashesArray($arr));
    }

    public function testSanitizeName(): void
    {
        $this->assertSame('JohnDoe', Sanitize::sanitizeName(false, 'John123 Doe!'));
        $this->assertSame('John Doe', Sanitize::sanitizeName(true, 'John123 Doe!'));
    }

    public function testSanitizeColorname(): void
    {
        $this->assertSame('Bl`%u@e', Sanitize::sanitizeColorname(false, 'Bl`%u@e'));
    }

    public function testSanitizeHtml(): void
    {
        $html = '<div>Hello<script>alert("x")</script></div>';
        $this->assertSame('Hello', Sanitize::sanitizeHtml($html));
    }

    public function testSanitizeMb(): void
    {
        $str = "Hello\xC3\x28World"; // invalid UTF-8 sequence
        $sanitized = Sanitize::sanitizeMb($str);
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
    }
}
