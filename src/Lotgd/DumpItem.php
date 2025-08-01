<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Helper functions for debugging output of arrays and values.
 */
class DumpItem
{
    /**
     * Dump an item or array as text for debugging purposes.
     *
     * @param mixed $item
     * @return string
     */
    /**
     * Return a printable representation of a variable for debugging.
     */
    public static function dump(mixed $item): string
    {
        $out = '';
        if (is_array($item)) {
            $out .= "array(" . count($item) . ") {<div style='padding-left:20pt;'>";
            foreach ($item as $key => $val) {
                $out .= "'$key' = '" . self::dump($val) . "'`n";
            }
            $out .= "</div>}";
        } else {
            $out .= $item;
        }
        return $out;
    }

    /**
     * Dump an item as PHP code representation.
     *
     * @param mixed  $item
     * @param string $indent Indentation characters
     * @return string
     */
    /**
     * Return a PHP code representation of the supplied variable.
     */
    public static function dumpAsCode(mixed $item, string $indent = "\t"): string
    {
        $out = '';
        $temp = is_array($item) ? $item : @unserialize($item);
        if (is_array($temp)) {
            $out .= "array(\n$indent";
            $row = [];
            foreach ($temp as $key => $val) {
                $row[] = "'$key'=&gt;" . self::dumpAsCode($val, $indent . "\t");
            }
            if (strlen(join(", ", $row)) > 80) {
                $out .= join(",\n$indent", $row);
            } else {
                $out .= join(", ", $row);
            }
            $out .= "\n$indent)";
        } else {
            $out .= "'" . htmlentities(addslashes($item), ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "'";
        }
        return $out;
    }
}
