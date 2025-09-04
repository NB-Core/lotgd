<?php

declare(strict_types=1);

namespace {
    if (!function_exists('httpget')) {
        function httpget(string $name)
        {
            return $_GET[$name] ?? '';
        }
    }

    if (!function_exists('httpset')) {
        function httpset(string $name, $value, bool $persistent = false): void
        {
            $_GET[$name] = $value;
        }
    }

    if (!function_exists('popup_footer')) {
        function popup_footer(): void
        {
        }
    }

}

namespace Lotgd\Tests {

    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

    final class MailWriteComposeTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $forms_output, $output;
            $session = ['user' => ['acctid' => 1, 'prefs' => []]];
            $forms_output = '';
            $output = new class {
                public function appoencode($data, $priv = false)
                {
                    return $data;
                }
            };
            $_GET = [];
            $_POST = [];
            Database::$mockResults = [];
        }

        public function testRecipientDropdownShownForPartialNames(): void
        {
            global $forms_output;
            $_POST['to'] = 'ja';
            $test_accounts_query_result = [
                ['login' => 'john', 'name' => 'John', 'superuser' => 0],
                ['login' => 'jane', 'name' => 'Jane', 'superuser' => 0],
            ];
            Database::$mockResults = [
                [],                         // exact login search
                $test_accounts_query_result, // fallback name search
            ];
            require __DIR__ . '/../pages/mail/case_write.php';
            $this->assertStringContainsString("<select name='to'", $forms_output);
        }
    }

}
