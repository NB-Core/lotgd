<?php
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
     */
    public static function show(): string
    {
        static $sent_css = false;
        if (!function_exists('debug_backtrace')) {
            return self::showNoBacktrace();
        }

        $bt = debug_backtrace();
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
        $x = 0;
        foreach ($bt as $val) {
            if ($x > 0 && $val['function'] != 'logd_error_handler') {
                $return .= "<b>$x:</b> <span class='function'>{$val['function']}(";
                $y = 0;
                if (isset($val['args']) && $val['args'] && is_array($val['args'])) {
                    foreach ($val['args'] as $v) {
                        if ($y > 0) {
                            $return .= ', ';
                        }
                        $return .= self::getType($v);
                        $y++;
                    }
                } elseif (isset($val['args']) && $val['args']) {
                    $return .= self::getType($val['args']);
                }
                if (! isset($val['file'])) {
                    $val['file'] = 'NO_FILE';
                }
                if (! isset($val['line'])) {
                    $val['line'] = 'NO_LINE';
                }
                $return .= ")</span>&nbsp;called from <b>{$val['file']}</b> on line <b>{$val['line']}</b><br>";
            }
            $x++;
        }
        $return .= '</div>';
        return $return;
    }

    /**
     * Format a value for output in a backtrace.
     *
     * @param mixed $in
     */
    public static function getType($in): string
    {
        $return = '';
        if (is_string($in)) {
            $return .= "<span class='string'>\"";
            if (strlen($in) > 25) {
                $return .= htmlentities(substr($in, 0, 25) . '...', ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
            } else {
                $return .= htmlentities($in, ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
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
