<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Redirect;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\PhpGenericEnvironment;

use const DATETIME_DATEMIN;
use const DATETIME_TODAY;

class DateTime
{
    /**
     * Time difference from now in human readable form.
     */
    public static function relTime(int $date, bool $short = true): string
    {
        $now = strtotime("now");
        $x = abs($now - $date);
        return self::readableTime($x, $short);
    }

    /**
     * Convert a duration in seconds to a readable string.
     *
     * Accepts seconds as a float but ignores the fractional part.
     */
    public static function readableTime(float $date, bool $short = true): string
    {
        $x = (int) abs($date);
        $d = (int)($x / 86400);
        $x %= 86400;
        $h = (int)($x / 3600);
        $x %= 3600;
        $m = (int)($x / 60);
        $x %= 60;
        $s = (int)$x;
        if ($short) {
            $array = ['d' => 'd', 'h' => 'h', 'm' => 'm', 's' => 's'];
            $array = Translator::translateInline($array, 'datetime');
            if ($d > 0) {
                $o = $d . $array['d'] . ($h > 0 ? $h . $array['h'] : '');
            } elseif ($h > 0) {
                $o = $h . $array['h'] . ($m > 0 ? $m . $array['m'] : '');
            } elseif ($m > 0) {
                $o = $m . $array['m'] . ($s > 0 ? $s . $array['s'] : '');
            } else {
                $o = $s . $array['s'];
            }
        } else {
            $array = [
                'day' => 'day',
                'days' => 'days',
                'hour' => 'hour',
                'hours' => 'hours',
                'minute' => 'minute',
                'minutes' => 'minutes',
                'second' => 'second',
                'seconds' => 'second',
            ];
            $array = Translator::translateInline($array, 'datetime');
            if ($d > 0) {
                $o = "$d " . ($d > 1 ? $array['days'] : $array['day']) . ($h > 0 ? ", $h " . ($h > 1 ? $array['hours'] : $array['hour']) : '');
            } elseif ($h > 0) {
                $o = "$h " . ($h > 1 ? $array['hours'] : $array['hour']) . ($m > 0 ? ", $m " . ($m > 1 ? $array['minutes'] : $array['minute']) : '');
            } elseif ($m > 0) {
                $o = "$m " . ($m > 1 ? $array['minutes'] : $array['minute']) . ($s > 0 ? ", $s " . ($s > 1 ? $array['seconds'] : $array['second']) : '');
            } else {
                $o = "$s " . ($s > 0 ? $array['seconds'] : $array['second']);
            }
        }
        return $o;
    }

    public static function relativeDate(string $indate): string
    {
        $laston = round((strtotime('now') - strtotime($indate)) / 86400, 0) . ' days';
        Translator::getInstance()->setSchema('datetime');
        if (substr($laston, 0, 2) == '1 ') {
            $laston = Translator::translateInline('1 day');
        } elseif (date('Y-m-d', strtotime($laston)) == date('Y-m-d')) {
            $laston = Translator::translateInline('Today');
        } elseif (date('Y-m-d', strtotime($laston)) == date('Y-m-d', strtotime('-1 day'))) {
            $laston = Translator::translateInline('Yesterday');
        } elseif (strpos($indate, DATETIME_DATEMIN) !== false) {
            $laston = Translator::translateInline('Never');
        } else {
            $laston = Translator::sprintfTranslate('%s days', round((strtotime('now') - strtotime($indate)) / 86400, 0));
            Output::getInstance()->rawOutput(Translator::tlbuttonClear());
        }
        Translator::getInstance()->setSchema();
        return $laston;
    }

    public static function checkDay(): void
    {
        global $session, $revertsession;
        $requestUri = PhpGenericEnvironment::getRequestUri();
        if ($session['user']['loggedin']) {
            Output::getInstance()->outputNotl('<!--CheckNewDay()-->', true);
            if (self::isNewDay()) {
                $session = $revertsession;
                $session['user']['restorepage'] = $requestUri;
                $session['allowednavs'] = [];
                Nav::add('', 'newday.php');
                Redirect::redirect('newday.php');
            }
        }
    }

    public static function isNewDay(int $now = 0): bool
    {
        global $session;
        if ($session['user']['lasthit'] == DATETIME_DATEMIN) {
            return true;
        }
        $t1 = self::gametime();
        $t2 = self::convertgametime(strtotime($session['user']['lasthit'] . ' +0000'));
        $d1 = gmdate('Y-m-d', $t1);
        $d2 = gmdate('Y-m-d', $t2);
        return $d1 != $d2;
    }

    public static function getGameTime(): string
    {
        $settings = Settings::getInstance();
        return gmdate($settings->getSetting('gametime', 'g:i a'), self::gametime());
    }

    public static function gameTime(): int
    {
        $time = self::convertgametime(strtotime('now'));
        return $time;
    }

    public static function convertGameTime(int $intime, bool $debug = false): int
    {
        $settings = Settings::getInstance();
        $intime -= $settings->getSetting('gameoffsetseconds', 0);
        $epoch = strtotime($settings->getSetting('game_epoch', gmdate('Y-m-d 00:00:00 O', strtotime('-30 days'))));
        $now = strtotime(gmdate('Y-m-d H:i:s O', $intime));
        $logd_timestamp = ($now - $epoch) * $settings->getSetting('daysperday', 4);
        if ($debug) {
            echo 'Game Timestamp: ' . $logd_timestamp . ', which makes it ' . gmdate('Y-m-d H:i:s', $logd_timestamp) . '<br>';
        }
        return $logd_timestamp;
    }

    public static function gameTimeDetails(): array
    {
        $settings = Settings::getInstance();
        $ret = [];
        $ret['now'] = date('Y-m-d 00:00:00');
        $ret['gametime'] = self::gametime();
        $ret['daysperday'] = $settings->getSetting('daysperday', 4);
        $ret['secsperday'] = 86400 / $ret['daysperday'];
        $ret['today'] = strtotime(gmdate('Y-m-d 00:00:00 O', $ret['gametime']));
        $ret['tomorrow'] = strtotime(gmdate('Y-m-d H:i:s O', $ret['gametime']) . ' + 1 day');
        $ret['tomorrow'] = strtotime(gmdate('Y-m-d 00:00:00 O', $ret['tomorrow']));
        $ret['secssofartoday'] = $ret['gametime'] - $ret['today'];
        $ret['secstotomorrow'] = $ret['tomorrow'] - $ret['gametime'];
        $ret['realsecssofartoday'] = $ret['secssofartoday'] / $ret['daysperday'];
        $ret['realsecstotomorrow'] = $ret['secstotomorrow'] / $ret['daysperday'];
        $ret['dayduration'] = ($ret['tomorrow'] - $ret['today']) / $ret['daysperday'];
        return $ret;
    }

    public static function secondsToNextGameDay(array|false $details = false): int
    {
        if ($details === false) {
            $details = self::gameTimeDetails();
        }
        return strtotime("{$details['now']} + {$details['realsecstotomorrow']} seconds");
    }

    public static function getMicroTime(): float
    {
        list($usec, $sec) = explode(' ', microtime());
        return ((float)$usec + (float)$sec);
    }

    public static function dateDifference(string $date_1, string $date_2 = DATETIME_TODAY, string $differenceFormat = '%R%a'): string
    {
        $datetime1 = date_create($date_1);
        $datetime2 = date_create($date_2);
        $interval = date_diff($datetime2, $datetime1);
        return $interval->format($differenceFormat);
    }

    public static function dateDifferenceEvents(string $date_1, bool $abs = false): int
    {
        $year  = (int) date('Y');
        $diff1 = (int) self::dateDifference($year . '-' . $date_1);
        $diff2 = (int) self::dateDifference(($year + 1) . '-' . $date_1);
        $diff3 = (int) self::dateDifference(($year - 1) . '-' . $date_1);

        if (abs($diff1) < abs($diff2) && abs($diff1) < abs($diff3)) {
            $d_return = $diff1;
        } elseif (abs($diff2) < abs($diff1) && abs($diff2) < abs($diff3)) {
            $d_return = $diff2;
        } else {
            $d_return = $diff3;
        }

        return $abs ? abs($d_return) : $d_return;
    }
}
