<?php

declare(strict_types=1);

namespace {
    if (! function_exists('URLEncode')) {
        function URLEncode(string $str): string
        {
            return urlencode($str);
        }
    }

    if (! function_exists('relativedate')) {
        function relativedate(string $date): string
        {
            return $date;
        }
    }
}

namespace Lotgd\Tests\Bans {

    use Lotgd\MySQL\Database;
    use Lotgd\Nav;
    use Lotgd\Output;
    use Lotgd\Settings;
    use Lotgd\Template;
    use Lotgd\Tests\Stubs\DummySettings;
    use Lotgd\Translator;
    use PHPUnit\Framework\TestCase;

    final class LegacyLookupIntegrationTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $_POST, $_GET;

            $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
            $_POST = [];
            $_GET = [];

            Database::$mockResults = [];
            Database::resetDoctrineConnection();

            Translator::enableTranslation(false);

            $settings = new DummySettings([
                'charset'            => 'UTF-8',
                'enabletranslation'  => false,
                'defaultlanguage'    => 'en',
                'cachetranslations'  => 0,
                'defaultskin'        => 'jade',
            ]);
            Settings::setInstance($settings);
            $GLOBALS['settings'] = $settings;

            $this->resetOutputSingleton();

            Template::getInstance()->setTemplate([
                'navhead'     => '<span>{title}</span>',
                'navhelp'     => '<span>{text}</span>',
                'navheadsub'  => '<span>{title}</span>',
                'navitem'     => '<a href="{link}">{text}</a>',
            ]);
            Nav::clearNav();
        }

        protected function tearDown(): void
        {
            Translator::enableTranslation(true);
            Settings::setInstance(null);
            unset($GLOBALS['settings']);
            Template::getInstance()->setTemplate([]);
            Nav::clearNav();
            Database::$mockResults = [];
            Database::resetDoctrineConnection();
            $this->resetOutputSingleton();
        }

        private function resetOutputSingleton(): void
        {
            Output::setInstance(null);
        }

        public function testBanSearchRendersOptionsWithoutTypeErrors(): void
        {
            $_POST['target'] = 'ja';

            Database::$mockResults = [
                [],
                [
                    ['acctid' => 101, 'login' => 'jane', 'name' => 'Jane'],
                    ['acctid' => 102, 'login' => 'jack', 'name' => 'Jack'],
                ],
                [
                    [
                        'ipfilter'  => '192.0.2.*',
                        'uniqueid'  => 'abc123',
                        'banner'    => 'Admin',
                        'banexpire' => DATETIME_DATEMAX,
                        'banreason' => 'Testing',
                        'lasthit'   => '2024-01-01 00:00:00',
                    ],
                ],
            ];

            $include = static function (): void {
                require __DIR__ . '/../../pages/bans/case_searchban.php';
            };
            $include();

            $buffer = Output::getInstance()->getOutput();

            $this->assertStringContainsString("<select name='target' id='target'>", $buffer);
            $this->assertStringContainsString("<option value='101'>jane</option>", $buffer);
            $this->assertStringContainsString("<option value='102'>jack</option>", $buffer);
        }
    }
}
