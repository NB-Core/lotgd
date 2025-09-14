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
