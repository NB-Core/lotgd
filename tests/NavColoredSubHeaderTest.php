<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Nav;
    use Lotgd\Output;
    use Lotgd\Template;

    require_once __DIR__ . '/../config/constants.php';
    if (!function_exists('modulehook')) {
        function modulehook($name, $data = [], $allowinactive = false, $only = false)
        {
            return $data;
        }
    }
    if (!function_exists('translate')) {
        function translate($t, $ns = false)
        {
            return $t;
        }
    }
    if (!function_exists('translate_inline')) {
        function translate_inline($t, $ns = false)
        {
            return $t;
        }
    }
    if (!function_exists('tlbutton_pop')) {
        function tlbutton_pop()
        {
            return '';
        }
    }
    if (!function_exists('tlschema')) {
        function tlschema($schema = false)
        {
        }
    }
    if (!function_exists('popup')) {
        function popup(string $page, string $size = '550x300')
        {
            return '';
        }
    }
    if (!function_exists('appoencode')) {
        function appoencode($data, $priv = false)
        {
            global $output;
            return $output->appoencode($data, $priv);
        }
    }

    final class NavColoredSubHeaderTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $nav, $template, $output;
            $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
            $nav = '';
            $output = new Output();
            $template = [
                'navhead' => '<span class="navhead">{title}</span>',
                'navheadsub' => '<span class="navheadsub">{title}</span>',
                'navitem' => '<a href="{link}">{text}</a>'
            ];
        }

        protected function tearDown(): void
        {
            global $session, $nav, $template, $output;
            unset($session, $nav, $template, $output);
        }

        public function testColoredSubHeaderRendersColors(): void
        {
            Nav::addHeader('Main', false);
            Nav::addColoredSubHeader('`!Sub');
            Nav::add('Link', 'foo.php');

            $navs = Nav::buildNavs();
            $this->assertStringContainsString("colLtBlue", $navs);
            $this->assertStringContainsString('</span>', $navs);
        }
    }
}
