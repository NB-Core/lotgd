<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Diagnostics;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class DiagnosticsPageTest extends TestCase
{
    protected function setUp(): void
    {
        global $diagnostics_output, $diagnostics_navs, $diagnostics_su_checks, $session;

        $diagnostics_output = '';
        $diagnostics_navs = [];
        $diagnostics_su_checks = [];
        $session = ['user' => ['acctid' => 1, 'superuser' => SU_MEGAUSER]];

        if (!class_exists('\\Lotgd\\SuAccess', false)) {
            eval('namespace Lotgd; class SuAccess { public static function check(int $level): void { global $diagnostics_su_checks, $diagnostics_output; $diagnostics_su_checks[] = ["level" => $level, "output_so_far" => $diagnostics_output]; } }');
        }
        if (!class_exists('\\Lotgd\\Nav\\SuperuserNav', false)) {
            eval('namespace Lotgd\\Nav; class SuperuserNav { public static function render(): void {} }');
        }
        if (!class_exists('\\Lotgd\\Nav', false)) {
            eval('namespace Lotgd; class Nav { public static function add($text, $link = false): void { global $diagnostics_navs; $diagnostics_navs[] = [$text, $link]; } }');
        }
        if (!class_exists('\\Lotgd\\Page\\Header', false)) {
            eval('namespace Lotgd\\Page; class Header { public static function pageHeader(...$args): void {} }');
        }
        if (!class_exists('\\Lotgd\\Page\\Footer', false)) {
            eval('namespace Lotgd\\Page; class Footer { public static function pageFooter(...$args): void {} }');
        }
        if (!class_exists('\\Lotgd\\Http', false)) {
            eval('namespace Lotgd; class Http { public static function get(string $name) { return $_GET[$name] ?? false; } }');
        }
        if (!class_exists('\\Lotgd\\Output', false)) {
            // Carries the members the page and Sanitize actually use.
            eval('namespace Lotgd; class Output {
                public static function getInstance() { return new self(); }
                public static function addHeadMarkup(string $markup): void {}
                public function getColormapEscaped(): string { return "1234567890!@#\\$%^&*()"; }
                public function rawOutput(string $in): void { global $diagnostics_output; $diagnostics_output .= $in; }
                public function outputNotl(...$args): void { global $diagnostics_output; $format = (string) array_shift($args); $diagnostics_output .= $args === [] ? $format : vsprintf($format, $args); }
                public function output(...$args): void { $this->outputNotl(...$args); }
            }');
        }
        if (!class_exists('\\Lotgd\\Translator', false)) {
            eval('namespace Lotgd; class Translator { public static function getInstance() { return new self(); } public function setSchema(?string $s = null): void {} }');
        }
        if (!class_exists('\\Lotgd\\Page', false)) {
            eval('namespace Lotgd; class Page { public static function getInstance() { return new self(); } public function getLogdVersion(): string { return "2.0.6 +nb Edition"; } }');
        }

        Settings::setInstance(new DummySettings([
            'installer_version' => '2.0.6 +nb Edition',
            'expiregamelog' => 30,
            'expiredebuglog' => 18,
            'expirefaillog' => 1,
            'expiredebug' => 7,
        ]));

        Database::$queries = [];
        Database::$mockResults = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();

        if (!defined('DB_CHOSEN')) {
            define('DB_CHOSEN', false);
        }
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Settings::setInstance(null);
        Database::resetDoctrineConnection();
        $_GET = [];
        unset($GLOBALS['session']);
        parent::tearDown();
    }

    private function renderPage(): string
    {
        if (!defined('DIAGNOSTICS_TEST')) {
            define('DIAGNOSTICS_TEST', true);
        }

        require __DIR__ . '/../diagnostics.php';

        global $diagnostics_output;

        return (string) $diagnostics_output;
    }

    /**
     * The gate has to run before anything is written, or a refused visitor has
     * already been handed part of the page.
     */
    public function testTheMegauserGateRunsBeforeAnyOutput(): void
    {
        $this->renderPage();

        global $diagnostics_su_checks;
        self::assertCount(1, $diagnostics_su_checks);
        self::assertSame(SU_MEGAUSER, $diagnostics_su_checks[0]['level']);
        self::assertSame('', $diagnostics_su_checks[0]['output_so_far']);
    }

    /**
     * ForcedNavigation refuses a URI that the previous render did not register,
     * so an offered window that is not in the navigation sends the operator to
     * badnav.php instead of showing the data.
     */
    public function testEveryOfferedWindowIsRegisteredWithTheNavigation(): void
    {
        $this->renderPage();

        global $diagnostics_navs;
        $links = array_filter(array_column($diagnostics_navs, 1));

        foreach (Diagnostics::WINDOWS as $hours) {
            self::assertContains(
                "diagnostics.php?hours=$hours",
                $links,
                "the $hours hour window must be reachable"
            );
        }
    }

    public function testTheSeverityFiltersAndRefreshAreRegisteredToo(): void
    {
        $this->renderPage();

        global $diagnostics_navs;
        $links = array_filter(array_column($diagnostics_navs, 1));

        self::assertContains('diagnostics.php?hours=24', $links);
        foreach (['info', 'warning', 'error', 'debug'] as $severity) {
            self::assertContains("diagnostics.php?hours=24&severity=$severity", $links);
        }
    }

    /**
     * A window the page does not offer must not produce links it never
     * registered, or the next click lands on badnav.php.
     */
    public function testAnUnofferedWindowFallsBackAndStillLinksConsistently(): void
    {
        $_GET['hours'] = '999999';

        $this->renderPage();

        global $diagnostics_navs;
        $links = array_filter(array_column($diagnostics_navs, 1));

        self::assertContains('diagnostics.php?hours=24', $links);
        foreach ($links as $link) {
            self::assertStringNotContainsString('999999', (string) $link);
        }
    }

    public function testThePageNamesItsSections(): void
    {
        $output = $this->renderPage();

        self::assertStringContainsString('Runtime', $output);
        self::assertStringContainsString('Timeline', $output);
        self::assertStringContainsString('Game log', $output);
        self::assertStringContainsString('Failed logins', $output);
        self::assertStringContainsString('Character audit trail', $output);
    }
}
