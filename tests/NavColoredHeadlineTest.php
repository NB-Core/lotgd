<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Nav;
    use Lotgd\Output;
    use Lotgd\Template;

    require_once __DIR__ . '/../config/constants.php';
    if (!function_exists('modulehook')) {
        function modulehook($name, $data = [], $allowinactive = false, $only = false) {
            return $data;
        }
    }
    if (!function_exists('translate')) {
        function translate($t, $ns = false) {
            return $t;
        }
    }
    if (!function_exists('translate_inline')) {
        function translate_inline($t, $ns = false) {
            return $t;
        }
    }
    if (!function_exists('tlbutton_pop')) {
        function tlbutton_pop() {
            return '';
        }
    }
    if (!function_exists('tlschema')) {
        function tlschema($schema = false) {
        }
    }
    if (!function_exists('popup')) {
        function popup(string $page, string $size = '550x300') {
            return '';
        }
    }
    if (!function_exists('rawoutput')) {
        function rawoutput($t) {
        }
    }
    if (!function_exists('output_notl')) {
        function output_notl($f, $t = true) {
        }
    }
    if (!function_exists('output')) {
        function output($f, $t = true) {
        }
    }
    if (!function_exists('debug')) {
        function debug($t, $force = false) {
        }
    }
    if (!function_exists('appoencode')) {
        function appoencode($data, $priv = false) {
            global $output;
            return $output->appoencode($data, $priv);
        }
    }

    final class NavColoredHeadlineTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $nav, $template, $output;
            $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
            $nav = '';
            $output = new Output();
            $template = [
                'navhead' => '<span class="navhead">{title}</span>',
                'navitem' => '<a href="{link}">{text}</a>'
            ];
        }

        protected function tearDown(): void
        {
            global $session, $nav, $template, $output;
            unset($session, $nav, $template, $output);
        }

        public function testRegularHeadlineUncolored(): void
        {
            Nav::addHeader('Section', false);
            Nav::add('Link', 'foo.php');

            $navs = Nav::buildNavs();
            $this->assertStringContainsString('<span class="navhead">Section</span>', $navs);
            $this->assertStringNotContainsString('colLtBlue', $navs);
        }

        public function testColoredHeadlineRendersColors(): void
        {
            Nav::addColoredHeadline('`!Section', false);
            Nav::add('Link', 'foo.php');

            $navs = Nav::buildNavs();
            $this->assertMatchesRegularExpression('/<span class="navhead">.*<span class=\'colLtBlue\'>Section<\/span>.*<\/span>/', $navs);
        }

        public function testColoredHeadlineSkippedWhenNoLinks(): void
        {
            $navs = Nav::buildNavs();
            $this->assertSame('', $navs);

            Nav::addColoredHeadline('`!Empty');
            $navs = Nav::buildNavs();
            $this->assertSame('', $navs);
        }

        public function testColoredHeadlineWithBlockedLink(): void
        {
            Nav::addColoredHeadline('`!Section', false);
            Nav::add('Link', 'foo.php');
            Nav::blockNav('foo.php');

            $navs = Nav::buildNavs();
            $this->assertSame('', $navs);
            Nav::unblockNav('foo.php');
        }

        public function testColoredHeadlineWithColoredNavItem(): void
        {
            Nav::addColoredHeadline('`!Section', false);
            Nav::add('`$Link', 'foo.php');

            $navs = Nav::buildNavs();
            $this->assertStringContainsString('colLtBlue', $navs);
            $this->assertStringContainsString('colLtRed', $navs);
            $this->assertStringContainsString('foo.php', $navs);
        }
    }
}
