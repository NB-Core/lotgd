<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * Security regression coverage for the game log's filter binding.
 *
 * gamelog.php built its category filter as
 * `AND ...category = '" . addslashes($category) . "'` from a value taken
 * straight off the query string. It was the last addslashes() SQL builder in
 * the core, and it survived so long because SqlAddslashesUsageCheck scanned
 * pages/ and src/ but no repository-root file.
 *
 * Both halves are covered here: the value must arrive as a bound parameter
 * rather than inside the statement, and the source must not grow a new
 * escape-and-interpolate filter.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class GameLogFilterBindingRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        global $forms_output;
        $forms_output = '';

        if (!class_exists('\\Lotgd\\SuAccess', false)) {
            eval('namespace Lotgd; class SuAccess { public static function check(int $level): void {} }');
        }
        if (!class_exists('\\Lotgd\\Nav\\SuperuserNav', false)) {
            eval('namespace Lotgd\\Nav; class SuperuserNav { public static function render(): void {} }');
        }
        if (!class_exists('\\Lotgd\\Output', false)) {
            eval('namespace Lotgd; class Output { public static function getInstance() { return new class { public function outputNotl(string $format, ...$args): void { global $forms_output; $forms_output .= vsprintf($format, $args); } }; } }');
        }
        if (!class_exists('\\Lotgd\\Page\\Header', false)) {
            eval('namespace Lotgd\\Page; class Header { public static function pageHeader(...$args): void {} }');
        }
        if (!class_exists('\\Lotgd\\Page\\Footer', false)) {
            eval('namespace Lotgd\\Page; class Footer { public static function pageFooter(...$args): void {} }');
        }
        if (!class_exists('\\Lotgd\\Nav', false)) {
            eval('namespace Lotgd; class Nav { public static function add(...$args): void {} }');
        }
        if (!class_exists('\\Lotgd\\Http', false)) {
            eval('namespace Lotgd; class Http { public static function get(string $name) { return $_GET[$name] ?? false; } }');
        }
        if (!function_exists('reltime')) {
            eval('function reltime(int $timestamp): string { return "ago"; }');
        }

        Database::$mockResults = [
            [['c' => 0]],
            [],
        ];
        Database::$queries = [];
        Database::resetDoctrineConnection();
        if (!defined('DB_CHOSEN')) {
            define('DB_CHOSEN', false);
        }
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        $_GET = [];
        parent::tearDown();
    }

    /**
     * A category carrying a quote must reach the driver as a parameter, never as
     * part of the statement text.
     */
    public function testHostileCategoryIsBoundRatherThanInterpolated(): void
    {
        if (!defined('GAMELOG_TEST')) {
            define('GAMELOG_TEST', true);
        }

        $hostileCategory = "maintenance' OR '1'='1";
        $_GET['cat'] = $hostileCategory;
        $_GET['severity'] = 'error';

        require __DIR__ . '/../../gamelog.php';

        $log = Database::getDoctrineConnection()->fetchAllLog;
        self::assertNotEmpty($log, 'gamelog.php is expected to query through the Doctrine connection');

        foreach ($log as $entry) {
            $sql = (string) ($entry['sql'] ?? '');

            // The value itself must never appear in the statement, in any form.
            self::assertStringNotContainsString($hostileCategory, $sql);
            self::assertStringNotContainsString("OR '1'='1", $sql);
            // Nor may it appear escaped, which is what the old code produced.
            self::assertStringNotContainsString(addslashes($hostileCategory), $sql);

            self::assertStringContainsString('category = :category', $sql);
            self::assertStringContainsString('severity = :severity', $sql);
            self::assertSame($hostileCategory, $entry['params']['category'] ?? null);
            self::assertSame('error', $entry['params']['severity'] ?? null);
        }
    }

    /**
     * The count and the row query must carry the same filter, or the pager would
     * page over a different set than the one it counted.
     */
    public function testBothTheCountAndTheRowQueryCarryTheBoundFilter(): void
    {
        if (!defined('GAMELOG_TEST')) {
            define('GAMELOG_TEST', true);
        }

        $_GET['cat'] = 'security';

        require __DIR__ . '/../../gamelog.php';

        $log = Database::getDoctrineConnection()->fetchAllLog;
        self::assertCount(2, $log, 'expected exactly the count query and the row query');

        self::assertStringContainsString('count(logid)', $log[0]['sql']);
        self::assertStringContainsString('category = :category', $log[0]['sql']);
        self::assertSame('security', $log[0]['params']['category'] ?? null);

        self::assertStringContainsString('category = :category', $log[1]['sql']);
        self::assertSame('security', $log[1]['params']['category'] ?? null);

        // No severity filter was requested, so neither statement may name one.
        self::assertStringNotContainsString(':severity', $log[0]['sql']);
        self::assertStringNotContainsString(':severity', $log[1]['sql']);
        self::assertArrayNotHasKey('severity', $log[0]['params']);
    }

    /**
     * Source-level guard matching the convention of the other binding regression
     * tests: the file must not reintroduce escape-and-interpolate filtering.
     */
    public function testSourceBindsTheFiltersAndUsesNoEscaping(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/gamelog.php');

        self::assertStringNotContainsString('addslashes', $source);
        self::assertStringContainsString('.category = :category', $source);
        self::assertStringContainsString('.severity = :severity', $source);
        self::assertStringContainsString('$filterParams', $source);
        self::assertStringContainsString('fetchAllAssociative($sql, $filterParams)', $source);
    }
}
