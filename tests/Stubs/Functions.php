<?php

declare(strict_types=1);

namespace {
    use Lotgd\Output;
    use Lotgd\Modules\ModuleManager;
    if (!function_exists('translate_inline')) {
        function translate_inline($text, $ns = false)
        {
            return $text;
        }
    }

    if (!function_exists('translate')) {
        function translate($text, $ns = false)
        {
            return $text;
        }
    }

    if (!function_exists('output_notl')) {
        function output_notl(string $format, ...$args)
        {
            global $forms_output;
            $forms_output .= vsprintf($format, $args);
        }
    }

    if (!function_exists('rawoutput')) {
        function rawoutput($text)
        {
            global $forms_output;
            $forms_output .= $text;
        }
    }

    if (!function_exists('output')) {
        function output(string $format, ...$args)
        {
            global $forms_output;
            $forms_output .= vsprintf($format, $args);
        }
    }

    if (!function_exists('addnav')) {
        function addnav(...$args): void
        {
        }
    }

    if (!function_exists('httppost')) {
        function httppost($name)
        {
            return $_POST[$name] ?? false;
        }
    }

    if (!function_exists('invalidatedatacache')) {
        function invalidatedatacache(string $name): void
        {
        }
    }

    if (!function_exists('modulehook')) {
        function modulehook($name, $data = [], $allowinactive = false, $only = false)
        {
            global $modulehook_returns;
            if (isset($modulehook_returns[$name])) {
                return array_merge($data, $modulehook_returns[$name]);
            }

            return $data;
        }
    }

    if (!function_exists('tlbutton_pop')) {
        function tlbutton_pop()
        {
            return '';
        }
    }

    if (!function_exists('getsetting')) {
        function getsetting($name, $default)
        {
            global $settings;
            if (isset($settings) && method_exists($settings, 'getSetting')) {
                return $settings->getSetting($name, $default);
            }

            return $default;
        }
    }

    if (!function_exists('debug')) {
        function debug($t, $force = false): void
        {
            global $forms_output;

            if (is_array($t)) {
                $t = appoencode(\Lotgd\DumpItem::dump($t), true);
            }

            $origin = ModuleManager::getMostRecentModule() ?? '';

            if ('' === $origin) {
                $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
                $origin = basename($trace[2]['file'] ?? '');
            }

            $forms_output .= "<button onclick=\"this.nextElementSibling.classList.toggle('hidden');\">Show Debug Output</button><div class='debug'>{$origin}: {$t}</div>";
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
            return Output::getInstance()->appoencode($data, $priv);
        }
    }

    if (!function_exists('sanitize')) {
        function sanitize($in)
        {
            return $in;
        }
    }

    if (!function_exists('full_sanitize')) {
        function full_sanitize($in)
        {
            return $in;
        }
    }

    if (!function_exists('translate_mail')) {
        function translate_mail($text, $to = 0)
        {
            return $text;
        }
    }

    if (!function_exists('e_rand')) {
        function e_rand(int $min = 0, int $max = PHP_INT_MAX): int
        {
            return mt_rand($min, $max);
        }
    }

    if (!function_exists('soap')) {
        function soap($input, $debug = false, $skiphook = false)
        {
            return $input;
        }
    }

    if (!function_exists('install_module')) {
        function install_module(string $module): bool
        {
            $GLOBALS['install_called'][] = $module;
            return true;
        }
    }

    if (!function_exists('uninstall_module')) {
        function uninstall_module(string $module): bool
        {
            $GLOBALS['uninstall_called'][] = $module;
            return true;
        }
    }

    if (!function_exists('activate_module')) {
        function activate_module(string $module): bool
        {
            $GLOBALS['activate_called'][] = $module;
            return true;
        }
    }

    if (!function_exists('deactivate_module')) {
        function deactivate_module(string $module): bool
        {
            $GLOBALS['deactivate_called'][] = $module;
            return true;
        }
    }

    if (!function_exists('injectmodule')) {
        function injectmodule(string $module, bool $b): void
        {
            $GLOBALS['inject_called'][] = $module;
        }
    }

    if (!function_exists('massinvalidate')) {
        function massinvalidate(string $name): void
        {
            $GLOBALS['massinvalidates'][] = $name;
        }
    }

    if (!function_exists('get_module_install_status')) {
        function get_module_install_status(bool $with_db = true): array
        {
            return $GLOBALS['module_status'] ?? [];
        }
    }

    if (!function_exists('apply_temp_stat')) {
        function apply_temp_stat($name, $value, $type = 'add')
        {
            return \Lotgd\PlayerFunctions::applyTempStat($name, $value, $type);
        }
    }

    if (!function_exists('check_temp_stat')) {
        function check_temp_stat($name, $color = false)
        {
            return \Lotgd\PlayerFunctions::checkTempStat($name, $color);
        }
    }

    if (!function_exists('suspend_temp_stats')) {
        function suspend_temp_stats()
        {
            return \Lotgd\PlayerFunctions::suspendTempStats();
        }
    }

    if (!function_exists('restore_temp_stats')) {
        function restore_temp_stats()
        {
            return \Lotgd\PlayerFunctions::restoreTempStats();
        }
    }

    if (!defined('DATACACHE_FILENAME_PREFIX')) {
        define('DATACACHE_FILENAME_PREFIX', 'datacache-');
    }
    if (!defined('LANGUAGE')) {
        define('LANGUAGE', 'en');
    }
}

namespace Lotgd {
    if (!function_exists('Lotgd\\getsetting')) {
        function getsetting(string|int $name, mixed $default = ''): mixed
        {
            if (function_exists('\\getsetting')) {
                return \getsetting($name, $default);
            }

            return $default;
        }
    }
}
