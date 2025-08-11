<?php

declare(strict_types=1);

namespace {
    if (!function_exists('httpget')) {
        function httpget(string $name) {
            return $_GET[$name] ?? '';
        }
    }

    if (!function_exists('httpset')) {
        function httpset(string $name, $value, bool $persistent = false): void
        {
            $_GET[$name] = $value;
        }
    }

    if (!function_exists('db_prefix')) {
        function db_prefix(string $name): string
        {
            return $name;
        }
    }

    if (!function_exists('db_query')) {
        function db_query(string $sql): array
        {
            global $test_accounts_query_result;
            if (strpos($sql, "login = '") !== false) {
                return [];
            }
            if (strpos($sql, 'name LIKE') !== false) {
                return $test_accounts_query_result;
            }
            return [];
        }
    }

    if (!function_exists('db_num_rows')) {
        function db_num_rows(array $result): int
        {
            return count($result);
        }
    }

    if (!function_exists('db_fetch_assoc')) {
        function db_fetch_assoc(array &$result): ?array
        {
            return array_shift($result);
        }
    }

    if (!function_exists('popup_footer')) {
        function popup_footer(): void {}
    }

}

namespace Lotgd\Tests {

use PHPUnit\Framework\TestCase;

final class MailWriteComposeTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $forms_output, $test_accounts_query_result, $output;
        $session = ['user' => ['acctid' => 1, 'prefs' => []]];
        $forms_output = '';
        $output = new class {
            public function appoencode($data, $priv = false)
            {
                return $data;
            }
        };
        $test_accounts_query_result = [
            ['login' => 'john', 'name' => 'John', 'superuser' => 0],
            ['login' => 'jane', 'name' => 'Jane', 'superuser' => 0],
        ];
        $_GET = [];
        $_POST = [];
    }

    public function testRecipientDropdownShownForPartialNames(): void
    {
        global $forms_output;
        $_POST['to'] = 'ja';
        require __DIR__ . '/../pages/mail/case_write.php';
        $this->assertStringContainsString("<select name='to'", $forms_output);
    }
}

}
