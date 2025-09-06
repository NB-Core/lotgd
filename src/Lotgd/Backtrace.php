<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Utility methods for displaying PHP backtraces.
 */
class Backtrace
{
    /**
     * Return empty string when backtrace is unavailable.
     */
    public static function showNoBacktrace(): string
    {
        return '';
    }

    /**
     * Build a HTML formatted call stack listing.
     *
     * @param array|null $trace Optional trace from Exception::getTrace()
     *
     * @return string HTML stack trace
     */
    public static function show(?array $trace = null): string
    {
        static $sent_css = false;

        if ($trace === null) {
            if (! function_exists('debug_backtrace')) {
                return self::showNoBacktrace();
            }

            $bt = debug_backtrace();
            // Remove call to this method from the stack
            array_shift($bt);
        } else {
            $bt = $trace;
        }

        $return = '';
        if (! $sent_css) {
            $return .= "<style type='text/css'>\n"
                . ".stacktrace { background-color: #FFFFFF; color: #000000; }\n"
                . ".stacktrace .function { color: #0000FF; }\n"
                . ".stacktrace .number { color: #FF0000; }\n"
                . ".stacktrace .string { color: #009900; }\n"
                . ".stacktrace .bool { color: #000099; font-weight: bold; }\n"
                . ".stacktrace .null { color: #999999; font-weight: bold; }\n"
                . ".stacktrace .object { color: #009999; font-weight: bold; }\n"
                . ".stacktrace .array { color: #990099; }\n"
                . ".stacktrace .unknown { color: #669900; font-weight: bold; }\n"
                . ".stacktrace blockquote { padding-top: 0px; padding-bottom: 0px; margin-top: 0px; margin-bottom: 0px; }\n"
                . '</style>';
            $sent_css = true;
        }

        $return .= "<div class='stacktrace'><b>Call Stack:</b><br>";
        $index = 1;
        foreach ($bt as $val) {
            if (isset($val['function']) && $val['function'] === 'logd_error_handler') {
                continue;
            }

            $func = $val['function'] ?? 'unknown';
            $return .= "<b>{$index}:</b> <span class='function'>{$func}(";

            $argIndex = 0;
            if (isset($val['args']) && is_array($val['args'])) {
                foreach ($val['args'] as $arg) {
                    if ($argIndex > 0) {
                        $return .= ', ';
                    }
                    $return .= self::getType($arg);
                    $argIndex++;
                }
            } elseif (isset($val['args'])) {
                $return .= self::getType($val['args']);
            }

            $file = $val['file'] ?? 'NO_FILE';
            $line = $val['line'] ?? 'NO_LINE';

            $return .= ")</span>&nbsp;called from <b>{$file}</b> on line <b>{$line}</b><br>";
            $index++;
        }

        $return .= '</div>';

        return $return;
    }

    /**
     * Format a value for output in a backtrace.
     *
     * @param mixed $in Value to render
     *
     * @return string Rendered output
     */
    public static function getType(mixed $in): string
    {
        $charset = 'UTF-8';
        if (! (defined('DB_NODB') && DB_NODB) && Settings::hasInstance()) {
            $charset = Settings::getInstance()->getSetting('charset', 'UTF-8');
        }
        $return = '';
        if (is_string($in)) {
            $return .= "<span class='string'>\"";
            if (strlen($in) > 25) {
                $return .= htmlentities(substr($in, 0, 25) . '...', ENT_COMPAT, $charset);
            } else {
                $return .= htmlentities($in, ENT_COMPAT, $charset);
            }
            $return .= "\"</span>";
        } elseif (is_bool($in)) {
            $return .= "<span class='bool'>" . ($in ? 'true' : 'false') . '</span>';
        } elseif (is_int($in)) {
            $return .= "<span class='number'>{$in}</span>";
        } elseif (is_float($in)) {
            $return .= "<span class='number'>" . round($in, 3) . '</span>';
        } elseif (is_object($in)) {
            $return .= "<span class='object'>" . get_class($in) . '</span>';
        } elseif (is_null($in)) {
            $return .= "<span class='null'>NULL</span>";
        } elseif (is_array($in)) {
            if (count($in) > 0) {
                $return .= "<span class='array'>Array(<blockquote>";
                $x = 0;
                foreach ($in as $key => $val) {
                    if ($x > 0) {
                        $return .= ', ';
                    }
                    $return .= self::getType($key) . '=>' . self::getType($val);
                    $x++;
                }
                $return .= "</blockquote>)</span>";
            } else {
                $return .= "<span class='array'>Array()</span>";
            }
        } else {
            $return .= "<span class='unknown'>Unknown[" . gettype($in) . "]</span>";
        }
        return $return;
    }
}
