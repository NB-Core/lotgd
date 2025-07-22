<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Helper functions to display and generate code representations of arrays.
 */
class OutputArray
{
    /**
     * Create a human readable representation of an array.
     *
     * @param array  $array  Data to output
     * @param string $prefix String used for indentation
     *
     * @return string Text representation
     */
    public static function output(array $array, string $prefix = ''): string
    {
        $out = '';
        foreach ($array as $key => $val) {
            $out .= $prefix . "[$key] = ";
            if (is_array($val)) {
                $out .= "array{\n" . self::output($val, $prefix . "[$key]") . "\n}\n";
            } else {
                $out .= $val . "\n";
            }
        }
        return $out;
    }

    /**
     * Return PHP code which recreates the given array.
     *
     * @param array $array Data to convert
     *
     * @return string Generated PHP code
     */
    public static function code(array $array): string
    {
        reset($array);
        $output = 'array(';
        $i = 0;
        foreach ($array as $key => $val) {
            if ($i > 0) {
                $output .= ', ';
            }
            if (is_array($val)) {
                $output .= "'" . addslashes($key) . "'=>" . self::code($val);
            } else {
                $output .= "'" . addslashes($key) . "'=>'" . addslashes($val) . "'";
            }
            $i++;
        }
        $output .= ")\n";
        return $output;
    }
}
