<?php

namespace Jaxon\Utils\Tests;

use Jaxon\Utils\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    /**
     * @var Translator
     */
    protected $xTranslator;

    protected function setUp(): void
    {
        $this->xTranslator = new Translator();
        $this->xTranslator->loadTranslations(__DIR__ . '/translations/test.en.php', 'en');
        $this->xTranslator->loadTranslations(__DIR__ . '/translations/test.fr.php', 'fr');
    }

    public function testFileWithBadData()
    {
        $this->assertFalse($this->xTranslator->loadTranslations(__DIR__ . '/translations/error.php', 'en'));
    }

    public function testFileNotFound()
    {
        $this->assertFalse($this->xTranslator->loadTranslations(__DIR__ . '/translations/not-found.php', 'en'));
    }

    public function testMissingEnTranslations()
    {
        $this->xTranslator->setLocale('en');
        $this->assertEquals('salutations.hello', $this->xTranslator->trans('salutations.hello'));
    }

    public function testMissingFrTranslations()
    {
        $this->xTranslator->setLocale('fr');
        $this->assertEquals('salutations.hello', $this->xTranslator->trans('salutations.hello'));
    }

    public function testSimpleEnTranslations()
    {
        $this->xTranslator->setLocale('en');
        $this->assertEquals('Good morning', $this->xTranslator->trans('salutations.morning'));
        $this->assertEquals('Good afternoon', $this->xTranslator->trans('salutations.afternoon'));
    }

    public function testSimpleFrTranslations()
    {
        $this->xTranslator->setLocale('fr');
        $this->assertEquals('Bonjour', $this->xTranslator->trans('salutations.morning'));
        $this->assertEquals('Bonsoir', $this->xTranslator->trans('salutations.afternoon'));
    }

    public function testEnTranslationsWithPlaceholders()
    {
        $this->xTranslator->setLocale('en');
        $this->assertEquals('Good morning Mr. Johnson',
            $this->xTranslator->trans('placeholders.morning', ['title' => 'Mr.', 'name' => 'Johnson']));
        $this->assertEquals('Good afternoon Mrs. Smith',
            $this->xTranslator->trans('placeholders.afternoon', ['title' => 'Mrs.', 'name' => 'Smith']));
    }

    public function testFrTranslationsWithPlaceholders()
    {
        $this->xTranslator->setLocale('fr');
        $this->assertEquals('Bonjour M. Pierre',
            $this->xTranslator->trans('placeholders.morning', ['title' => 'M.', 'name' => 'Pierre']));
        $this->assertEquals('Bonsoir Mme Paule',
            $this->xTranslator->trans('placeholders.afternoon', ['title' => 'Mme', 'name' => 'Paule']));
    }

    public function testExplicitEnTranslations()
    {
        $this->xTranslator->setLocale('fr');
        $this->assertEquals('Good morning Mr. Johnson',
            $this->xTranslator->trans('placeholders.morning', ['title' => 'Mr.', 'name' => 'Johnson'], 'en'));
        $this->assertEquals('Good afternoon Mrs. Smith',
            $this->xTranslator->trans('placeholders.afternoon', ['title' => 'Mrs.', 'name' => 'Smith'], 'en'));
    }

    public function testExplicitFrTranslations()
    {
        $this->xTranslator->setLocale('en');
        $this->assertEquals('Bonjour M. Pierre',
            $this->xTranslator->trans('placeholders.morning', ['title' => 'M.', 'name' => 'Pierre'], 'fr'));
        $this->assertEquals('Bonsoir Mme Paule',
            $this->xTranslator->trans('placeholders.afternoon', ['title' => 'Mme', 'name' => 'Paule'], 'fr'));
    }
}
