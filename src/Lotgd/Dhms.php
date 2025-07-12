<?php
declare(strict_types=1);
namespace Lotgd;

/**
 * Small helper for converting seconds to day/hour/minute strings.
 */
class Dhms
{
    /**
     * Convert seconds into a translated d/h/m/s string.
     *
     * @param float|int $secs Seconds to convert
     * @param bool      $dec  Include decimal part for seconds
     *
     * @return string
     */
    public static function format(int|float $secs, bool $dec = false): string
    {
        if ($dec === false) {
            $secs = round($secs, 0);
        }
        return (int)($secs / 86400)
            . translate_inline('d', 'datetime')
            . (int)($secs / 3600 % 24)
            . translate_inline('h', 'datetime')
            . (int)($secs / 60 % 60)
            . translate_inline('m', 'datetime')
            . ($secs % 60)
            . ($dec ? substr((string)($secs - (int)$secs), 1) : '')
            . translate_inline('s', 'datetime');
    }
}

