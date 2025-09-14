<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Settings;

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
     * @param mixed  $item   Item to dump
     * @param string $indent Indentation characters
     *
     * @return string
     */
    public static function dumpAsCode(mixed $item, string $indent = "\t"): string
    {
        $out = '';
        $temp = $item;

        if (is_string($item)) {
            $unserialized = self::tryUnserialize($item);
            if ($unserialized !== null) {
                $temp = $unserialized;
            }
        }

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
            $out .= "'" . htmlentities(addslashes((string) $temp), ENT_COMPAT, Settings::getInstance()->getSetting('charset', 'UTF-8')) . "'";
        }

        return $out;
    }

    /**
     * Attempt to unserialize a string without emitting warnings.
     *
     * @param string $value Serialized string
     *
     * @return mixed|null Unserialized value or null on failure
     */
    private static function tryUnserialize(string $value): mixed
    {
        try {
            set_error_handler(static function (): bool {
                return true;
            });
            $result = @unserialize($value);
            restore_error_handler();

            if (false === $result && 'b:0;' !== $value) {
                return null;
            }

            return $result;
        } catch (\Throwable) {
            restore_error_handler();
            return null;
        }
    }
}
