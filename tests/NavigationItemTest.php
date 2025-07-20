<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Nav\NavigationItem;
    use Lotgd\Output;
    use Lotgd\Template;
    use Lotgd\Nav;

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
    if (!function_exists('appoencode')) {
        function appoencode($data, $priv = false) {
            global $output;
            return $output->appoencode($data, $priv);
        }
    }

    final class NavigationItemTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $nav, $template, $output;
            $session = ['user' => ['prefs' => []], 'allowednavs' => [], 'loggedin' => false];
            $nav = '';
            $output = new Output();
            $template = [
                'navitem' => '<a href="{link}"{accesskey}{popup}>{text}</a>'
            ];
        }

        protected function tearDown(): void
        {
            global $session, $nav, $template, $output;
            unset($session, $nav, $template, $output);
        }

        public function testRenderProducesLinkHtml(): void
        {
            $item = new NavigationItem('Home', 'index.php');
            $html = $item->render();
            $this->assertStringContainsString('index.php', $html);
            $this->assertStringContainsString('navhi', $html);
        }
    }
}
