<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Dhms;
use Lotgd\EmailValidator;
use Lotgd\Output;
use Lotgd\SafeEscape;
use Lotgd\Sanitize;
use Lotgd\Stripslashes;
use PHPUnit\Framework\TestCase;

final class UtilityTest extends TestCase
{
    protected function setUp(): void
    {
        Output::getInstance();
    }

    public function testEmailValidatorValidAndInvalid(): void
    {
        $this->assertTrue(EmailValidator::isValid('user@example.com'));
        $this->assertFalse(EmailValidator::isValid('invalid-email'));
    }

    public function testSafeEscapeEscapesQuotesOnce(): void
    {
        $in = "O'Reilly \"book\"";
        $escaped = SafeEscape::escape($in);
        $this->assertSame("O\\'Reilly \\\"book\\\"", $escaped);

        $preEscaped = "O\\'Reilly";
        $this->assertSame($preEscaped, SafeEscape::escape($preEscaped));
    }

    public function testStripslashesDeepRemovesSlashes(): void
    {
        $input = ["a\\b", ["c\\d"]];
        $expected = ["ab", ["cd"]];
        $this->assertSame($expected, Stripslashes::deep($input));
    }

    public function testDhmsFormatVarious(): void
    {
        $this->assertSame('0d1h1m1s', Dhms::format(3661));
        $this->assertSame('0d0h0m0s', Dhms::format(0));
        $this->assertSame('1d1h1m1s', Dhms::format(90061));
    }

    public function testSanitize(): void
    {
        $this->assertSame('Hello World!', Sanitize::sanitize('Hello `&World`1!'));
    }

    public function testColorSanitize(): void
    {
        $this->assertSame('Hello `nWorld', Sanitize::colorSanitize('Hello `n`&World'));
    }

    public function testCommentSanitize(): void
    {
        $this->assertSame('Look ``here', Sanitize::commentSanitize('Look `here'));
    }

    public function testPreventColors(): void
    {
        $this->assertSame('Hi &#0096;&World&#0096;0', Sanitize::preventColors('Hi `&World`0'));
    }
}
