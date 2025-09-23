<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class GameLogSystemLabelTest extends TestCase
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
        if (!function_exists('page_header')) {
            eval('function page_header($title): void {}');
        }
        if (!function_exists('page_footer')) {
            eval('function page_footer(): void {}');
        }
        if (!function_exists('httpget')) {
            eval('function httpget(string $name) { return $_GET[$name] ?? ""; }');
        }
        if (!function_exists('reltime')) {
            eval('function reltime(int $timestamp): string { return "ago"; }');
        }

        Database::$mockResults = [
            [['c' => 1]],
            [[
                'date' => '2024-01-01 00:00:00',
                'category' => 'general',
                'message' => 'Something happened',
                'name' => '',
                'who' => 0,
            ]],
        ];
        if (!defined('DB_CHOSEN')) {
            define('DB_CHOSEN', false);
        }
        $_GET = [];
    }

    public function testSystemLabelShownForWhoZero(): void
    {
        if (!defined('GAMELOG_TEST')) {
            define('GAMELOG_TEST', true);
        }
        require __DIR__ . '/../gamelog.php';
        global $forms_output;
        $this->assertStringContainsString('System: Something happened', $forms_output);
    }
}
