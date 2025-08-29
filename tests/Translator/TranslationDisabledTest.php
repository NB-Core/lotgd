<?php

declare(strict_types=1);

namespace Lotgd\Tests\Translator;

use Lotgd\Translator;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\Database as StubDatabase;
use PHPUnit\Framework\TestCase;

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
