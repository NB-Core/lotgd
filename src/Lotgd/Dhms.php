<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Translator;

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
        $secsInt = $dec ? (int)$secs : (int)round($secs, 0);
        $decimal = $dec ? substr((string)($secs - $secsInt), 1) : '';

        return intdiv($secsInt, 86400)
            . Translator::translateInline('d', 'datetime')
            . intdiv($secsInt, 3600) % 24
            . Translator::translateInline('h', 'datetime')
            . intdiv($secsInt, 60) % 60
            . Translator::translateInline('m', 'datetime')
            . ($secsInt % 60)
            . $decimal
            . Translator::translateInline('s', 'datetime');
    }
}
