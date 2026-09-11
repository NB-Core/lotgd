<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Translator;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * Runs in its own process: this class define()s process-global constants, and a
 * constant cannot be undefined. Without isolation the first test to run here
 * decides them for every test that follows, which is one of the two reasons the
 * suite used to pass only in alphabetical order.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class TranslatorSprintfTranslateTest extends TestCase
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

    public function testSprintfTranslateWithMatchingArguments(): void
    {
        $result = Translator::sprintfTranslate('Hello %s', 'World');
        $this->assertSame('Hello World', $result);
    }

    public function testSprintfTranslateWithMissingArgumentsPadsWithoutWarning(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];
            return true;
        }, E_USER_WARNING);
        $result = Translator::sprintfTranslate('Value: %s %s', 'First');
        restore_error_handler();
        $this->assertSame('Value: First ', $result);
        $this->assertEmpty($warnings);
    }

    public function testSprintfTranslateWithExtraArgumentsDropsExtraWithoutWarning(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];
            return true;
        }, E_USER_WARNING);
        $result = Translator::sprintfTranslate('Values: %s and %s', 'First', 'Second', 'Third');
        restore_error_handler();
        $this->assertSame('Values: First and Second', $result);
        $this->assertEmpty($warnings);
    }
}
