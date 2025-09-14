<?php

declare(strict_types=1);

namespace {
    if (!function_exists('httpallpost')) {
        function httpallpost(): array
        {
            return $_POST ?? [];
        }
    }
    if (!function_exists('httppost')) {
        function httppost(string $name): string
        {
            return $_POST[$name] ?? '';
        }
    }
    if (!function_exists('httpget')) {
        function httpget(string $name): string
        {
            return $_GET[$name] ?? '';
        }
    }
    if (!function_exists('getsetting')) {
        function getsetting(string $setting, string $default = ''): string
        {
            return $default;
        }
    }
    if (!function_exists('httpset')) {
        function httpset(mixed ...$args): void
        {
        }
    }
    if (!function_exists('debuglog')) {
        function debuglog(string $message, mixed $userid = null): void
        {
        }
    }
    if (!function_exists('debug')) {
        function debug(mixed ...$args): void
        {
        }
    }
    if (!function_exists('sanitize_colorname')) {
        function sanitize_colorname(mixed ...$args): string
        {
            return $args[1] ?? '';
        }
    }
    if (!function_exists('sanitize_html')) {
        function sanitize_html(string $str): string
        {
            return $str;
        }
    }
    if (!function_exists('soap')) {
        function soap(string $str): string
        {
            return $str;
        }
    }
    if (!function_exists('show_bitfield')) {
        function show_bitfield(mixed $value): string
        {
            return (string) $value;
        }
    }
}


namespace Lotgd\Tests {
    use PHPUnit\Framework\TestCase;

    final class UserSaveNoOldvaluesTest extends TestCase
    {
        public function testPostWithoutOldvaluesDoesNotTriggerNotices(): void
        {
            $errors = [];
            set_error_handler(function (int $errno, string $errstr) use (&$errors): bool {
                if ($errno === E_NOTICE || $errno === E_WARNING) {
                    $errors[] = $errstr;
                }
                return true;
            });

            $include = function (): void {
                global $_POST, $_GET, $output, $session, $userid, $userinfo;
                $_POST = ['playername' => 'Foo'];
                $_GET = [];
                $session = ['user' => ['acctid' => 1, 'superuser' => 0, 'name' => 'Admin']];
                $userid = 1;
                $userinfo = [];
                $output = new class {
                    public function outputNotl(string $format, mixed ...$args): void
                    {
                    }
                    public function output(string $format, mixed ...$args): void
                    {
                    }
                };
                require __DIR__ . '/../pages/user/user_save.php';
            };

            \Closure::bind($include, null, null)();

            restore_error_handler();
            $this->assertSame([], $errors);
        }
    }
}
