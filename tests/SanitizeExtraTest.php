<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\Sanitize;
use Lotgd\Output;

require_once __DIR__ . '/../config/constants.php';

if (!function_exists('getsetting')) {
    function getsetting(string|int $name, mixed $default = ''): mixed
    {
        return $default;
    }
}

if (!class_exists('DummySettingsSanitize')) {
    class DummySettingsSanitize extends Lotgd\Settings
    {
        private array $values;
        public function __construct(array $values = [])
        {
            $this->values = $values;
        }
        public function getSetting(string|int $name, mixed $default = false): mixed
        {
            return $this->values[$name] ?? $default;
        }
        public function loadSettings(): void
        {
        }
        public function clearSettings(): void
        {
        }
        public function saveSetting(string|int $name, mixed $value): bool
        {
            $this->values[$name] = $value;
            return true;
        }
        public function getArray(): array
        {
            return $this->values;
        }
    }
}

final class SanitizeExtraTest extends TestCase
{
    protected function setUp(): void
    {
        global $output, $settings;
        $output = new Output();
        $settings = new DummySettingsSanitize(['charset' => 'UTF-8']);
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

    public function testFullSanitize(): void
    {
        $this->assertSame('HelloWorld', Sanitize::fullSanitize('Hello`xWorld'));
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
        global $output;
        $output = new Output();
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
        $this->assertSame('Hello', Sanitize::sanitizeMb($str));
    }
}
