<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Utility for converting newline characters to appoencode style linebreaks.
 */
class Nltoappon
{
    /**
     * Replace newline characters with `n sequences used by appoencode.
     *
     * @param string $in Text to convert
     *
     * @return string Converted text
     */
    public static function convert(string $in): string
    {
        $out = str_replace("\r\n", "\n", $in);
        $out = str_replace("\r", "\n", $out);
        $out = str_replace("\n", "`n", $out);
        return $out;
    }
}
