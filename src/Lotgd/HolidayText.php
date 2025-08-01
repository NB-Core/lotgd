<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Text helper to replace certain messages with seasonal variants.
 */
class HolidayText
{
    /**
     * Apply holiday replacements to a given text string.
     */
    public static function holidayize(string $text, string $type = 'unknown'): string
    {
        global $session,$currenthook;
        if (isset($session['user'])) {
            if (!isset($session['user']['prefs']) || !is_array($session['user']['prefs'])) {
                $session['user']['prefs'] = [];
            }
            if (!isset($session['user']['prefs']['ihavenocheer'])) {
                $session['user']['prefs']['ihavenocheer'] = 0;
            }
            if ($session['user']['prefs']['ihavenocheer']) {
                return $text;
            }
        }
        $args = ['text' => $text, 'type' => $type];
        if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
            if (isset($currenthook) && $currenthook === 'holiday') {
                return $text;
            }
            $args = modulehook('holiday', $args);
        }
        $text = $args['text'];
        return $text;
    }
}
