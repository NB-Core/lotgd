<?php

declare(strict_types=1);

namespace Lotgd\Tests\Translator;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for translateMail() recipient language lookup.
 */
final class TranslateMailRecipientLanguageLookupTest extends TestCase
{
    private function resetTranslatorCache(): void
    {
        $reflection = new \ReflectionClass(Translator::class);
        $property = $reflection->getProperty('translation_table');
        $property->setAccessible(true);
        $property->setValue([]);
    }

    protected function setUp(): void
    {
        $GLOBALS['settings'] = new DummySettings([
            'enabletranslation' => true,
            'cachetranslations' => 0,
            'defaultlanguage'   => 'en',
        ]);
        $GLOBALS['session'] = [];
        $GLOBALS['REQUEST_URI'] = '/mail.php';
        $GLOBALS['accounts_table'] = [
            42 => ['prefs' => serialize(['language' => 'fr'])],
        ];

        if (!defined('DB_CHOSEN')) {
            define('DB_CHOSEN', true);
        }
        if (!defined('LANGUAGE')) {
            define('LANGUAGE', 'en');
        }

        Translator::enableTranslation(true);
        $this->resetTranslatorCache();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['REQUEST_URI'], $GLOBALS['accounts_table']);
        Database::resetDoctrineConnection();
        Translator::enableTranslation(true);
        $this->resetTranslatorCache();
    }

    public function testTranslateMailLoadsRecipientLanguageViaBoundIntegerParameter(): void
    {
        $connection = new DoctrineConnection();
        Database::setDoctrineConnection($connection);
        $connection->fetchAllResults[] = [
            ['intext' => 'Hello %s', 'outtext' => 'Bonjour %s'],
        ];

        $translated = Translator::translateMail(['Hello %s', 'traveler'], 42);

        $this->assertSame('Bonjour traveler', $translated);
        $this->assertSame(['acctid' => 42], $connection->executeQueryParams[0] ?? []);
        $this->assertSame(['acctid' => ParameterType::INTEGER], $connection->executeQueryTypes[0] ?? []);
        $this->assertSame('fr', $connection->lastFetchAllParams['language'] ?? null);
    }
}

