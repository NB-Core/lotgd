<?php
namespace Lotgd;

class SafeEscape
{
    /**
     * Add slashes to quote characters that are not already escaped.
     */
    public static function escape(string $input): string
    {
        $prevchar = '';
        $out = '';
        for ($x = 0; $x < strlen($input); $x++) {
            $char = substr($input, $x, 1);
            if (($char == "'" || $char == '"') && $prevchar != '\\') {
                $char = '\\' . $char;
            }
            $out .= $char;
            $prevchar = $char;
        }
        return $out;
    }
}
