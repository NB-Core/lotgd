<?php

declare(strict_types=1);

namespace Lotgd\Tests\Translator;

use Lotgd\Translator;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\Database as StubDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Runs in its own process: this class define()s process-global constants, and a
 * constant cannot be undefined. Without isolation the first test to run here
 * decides them for every test that follows, which is one of the two reasons the
 * suite used to pass only in alphabetical order.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class TranslationDisabledTest extends TestCase
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
        if (!defined('DB_CHOSEN')) {
            define('DB_CHOSEN', true);
        }
        Translator::enableTranslation(true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['REQUEST_URI'], $GLOBALS['language']);
        Translator::enableTranslation(true);
        StubDatabase::$tableExists = true;
    }

    public function testFallsBackWhenTranslationsTableMissing(): void
    {
        StubDatabase::$tableExists = false;
        Translator::translate('trigger');
        $this->assertSame('Hello', Translator::translate('Hello'));
        $this->assertSame('Hello', Translator::translateInline('Hello'));
        $this->assertSame('Mail user', Translator::translateMail(['Mail %s', 'user']));
        $this->assertSame('Hello', Translator::tl('Hello'));
    }
}
