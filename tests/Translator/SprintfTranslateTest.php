<?php

declare(strict_types=1);

namespace Lotgd\Tests\Translator;

use Lotgd\Translator;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class SprintfTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['settings'] = new DummySettings(['enabletranslation' => true]);
        $GLOBALS['session'] = [];
        $GLOBALS['REQUEST_URI'] = '/';
        if (!defined('LANGUAGE')) {
            define('LANGUAGE', 'en');
        }
        $GLOBALS['language'] = 'en';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['REQUEST_URI'], $GLOBALS['language']);
    }

    public function testNoArgumentPadsEmptyString(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];
            return true;
        }, E_USER_WARNING);
        $result = Translator::sprintfTranslate('Value: %s');
        restore_error_handler();
        $this->assertSame('Value: ', $result);
        $this->assertSame([], $warnings);
    }

    public function testSupportsPositionWidthAndLiteralPercent(): void
    {
        $result = Translator::sprintfTranslate('Progress: %1$s %2$02d%%', 'Done', 3);
        $this->assertSame('Progress: Done 03%', $result);
    }

    public function testNonSequentialPositionWithMissingArgumentPads(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];
            return true;
        }, E_USER_WARNING);
        $result = Translator::sprintfTranslate('%1$s %3$s', 'First');
        restore_error_handler();
        $this->assertSame('First ', $result);
        $this->assertSame([], $warnings);
    }

    public function testNonSequentialPositionWithMissingArgumentPadsWithPrefix(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];
            return true;
        }, E_USER_WARNING);
        $result = Translator::sprintfTranslate('Value: %1$s %3$s', 'foo');
        restore_error_handler();
        $this->assertSame('Value: foo ', $result);
        $this->assertSame([], $warnings);
    }

    public function testStrayPercentDoesNotCrash(): void
    {
        $result = Translator::sprintfTranslate('Value with stray % sign');
        $this->assertSame('Value with stray % sign', $result);
    }
}
