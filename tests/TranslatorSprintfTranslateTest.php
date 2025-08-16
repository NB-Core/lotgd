<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Translator;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

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
