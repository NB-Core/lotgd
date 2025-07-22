<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Forms;

    require_once __DIR__ . '/../config/constants.php';

    if (!function_exists('translate_inline')) {
        function translate_inline($t, $ns = false)
        {
            return $t;
        }
    }
    if (!function_exists('translate')) {
        function translate($t, $ns = false)
        {
            return $t;
        }
    }
    if (!function_exists('modulehook')) {
        function modulehook($name, $data)
        {
            return $data;
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
    if (!function_exists('getsetting')) {
        function getsetting($name, $default)
        {
            return $default;
        }
    }
    if (!function_exists('httppost')) {
        function httppost($name)
        {
            return false;
        }
    }
    if (!function_exists('rawoutput')) {
        function rawoutput($t)
        {
            global $forms_output;
            $forms_output .= $t;
        }
    }
    if (!function_exists('output_notl')) {
        function output_notl($f, $t = true)
        {
            global $forms_output;
            $forms_output .= sprintf($f, $t);
        }
    }
    if (!function_exists('output')) {
        function output($f, $t = true)
        {
            global $forms_output;
            $forms_output .= sprintf($f, $t);
        }
    }
    if (!function_exists('debug')) {
        function debug($t, $force = false)
        {
        }
    }

    final class FormsTest extends TestCase
    {
        protected function setUp(): void
        {
            global $forms_output;
            $forms_output = '';
        }

        public function testCheckboxChecked(): void
        {
            global $forms_output;
            Forms::showForm(['flag' => 'Flag,checkbox'], ['flag' => 1]);
            $this->assertStringContainsString("type='checkbox' name='flag' value='1' checked", $forms_output);
        }

        public function testCheckboxUnchecked(): void
        {
            global $forms_output;
            Forms::showForm(['flag' => 'Flag,checkbox'], ['flag' => 0]);
            $this->assertStringContainsString("type='checkbox' name='flag' value='1'", $forms_output);
            $this->assertStringNotContainsString('checked', $forms_output);
        }
    }
}
