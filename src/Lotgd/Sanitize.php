<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Settings;

class Sanitize
{
    public const URI_MAX_LENGTH = 200;

    /**
     * Strip game colour codes from a string.
     *
     * @param string|int|float|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function sanitize(string|int|float|null $in): string
    {
        if ($in === '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`][0' . Output::getInstance()->getColormapEscaped() . 'bicnHw]/', '', (string) $in);
        return $out;
    }

    /**
     * Remove new line codes from a string.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function newlineSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/`n/', '', $in);
        return $out;
    }

    /**
     * Remove colour codes except text formatting.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function colorSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`][0' . Output::getInstance()->getColormapEscaped() . 'cbi]/', '', $in);
        return $out;
    }

    /**
     * Prepare a comment string for output.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function commentSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`](?=[^0' . Output::getInstance()->getColormapEscaped() . '])/', chr(1) . chr(1), $in);
        $out = str_replace(chr(1), '`', $out);
        return $out;
    }

    /**
     * Sanitize string for sending via Logdnet.
     *
     * @param string $in Input value
     *
     * @return string Sanitized value
     */
    public static function logdnetSanitize(string $in): string
    {
        $out = preg_replace('/[`](?=[^0' . Output::getInstance()->getColormapEscaped() . 'bicn])/', chr(1) . chr(1), $in);
        $out = str_replace(chr(1), '`', $out);
        return $out;
    }

    /**
     * Remove all colour codes from a string.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function fullSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`]./', '', $in);
        return $out;
    }

    /**
     * Strip colour information from a command string.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function cmdSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace("'[&?]c=[[:digit:]-]+'", '', $in);
        return $out;
    }

    /**
     * Remove commentary scroll parameters from a URI.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function comscrollSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace("'&c(omscroll)?=([[:digit:]]|-)*'", '', $in);
        $out = preg_replace("'\\?c(omscroll)?=([[:digit:]]|-)*'", '?', $out);
        $out = preg_replace("'&(refresh|comment)=1'", '', $out);
        $out = preg_replace("'\\?(refresh|comment)=1'", '?', $out);
        return $out;
    }

    /**
     * Escape colour markers to prevent rendering.
     *
     * @param string $in Input value
     *
     * @return string Sanitized value
     */
    public static function preventColors(string $in): string
    {
        return str_replace('`', '&#0096;', $in);
    }

    /**
     * Clean a URI for translator usage.
     *
     * @param string $in Input URI
     *
     * @return string Sanitized URI
     */
    public static function translatorUri(string $in): string
    {
        $uri = self::comscrollSanitize($in);
        $uri = self::cmdSanitize($uri);
        $uri = str_replace(["'", '"', '`', ';'], '', $uri);
        if (substr($uri, -1) == '?') {
            $uri = substr($uri, 0, -1);
        }
        if (strlen($uri) > self::URI_MAX_LENGTH) {
            $uri = substr($uri, 0, self::URI_MAX_LENGTH);
        }
        return $uri;
    }

    /**
     * Extract the page path from a URI.
     *
     * @param string $in Input URI
     *
     * @return string Page path
     */
    public static function translatorPage(string $in): string
    {
        $page = $in;
        if (strpos($page, '?') !== false) {
            $page = substr($page, 0, strpos($page, '?'));
        }
        $page = str_replace(["'", '"', '`', ';'], '', $page);
        return $page;
    }

    /**
     * Sanitize a module name.
     *
     * @param string $in Module name
     *
     * @return string Sanitized name
     */
    public static function modulenameSanitize(string $in): string
    {
        return preg_replace("'[^0-9A-Za-z_]'", '', $in);
    }

    /**
     * Recursively stripslashes input values.
     *
     * @param array|string $given Input value
     *
     * @return array|string Cleaned value
     */
    public static function stripslashesArray(array|string $given): array|string
    {
        return is_array($given) ? array_map([self::class, 'stripslashesArray'], $given) : stripslashes($given);
    }

    /**
     * Strip non alphabetic characters from a name.
     *
     * @param bool   $spaceallowed Allow spaces
     * @param string $inname       Input name
     *
     * @return string Sanitized name
     */
    public static function sanitizeName(bool $spaceallowed, string $inname): string
    {
        $expr = $spaceallowed ? '([^[:alpha:] _-])' : '([^[:alpha:]])';
        return preg_replace($expr, '', $inname);
    }

    /**
     * Sanitize a color name respecting admin settings.
     *
     * @param bool   $spaceallowed Allow spaces
     * @param string $inname       Name to sanitize
     * @param bool   $admin        Whether admin overrides are allowed
     *
     * @return string Sanitized name
     */
    public static function sanitizeColorname(bool $spaceallowed, string $inname, bool $admin = false): string
    {
        $settings = Settings::getInstance();
        if ($admin && $settings->getSetting('allowoddadminrenames', 0)) {
            return $inname;
        }
        $output = Output::getInstance();
        $expr = $spaceallowed ? '([^[:alpha:]`0' . $output->getColormapEscaped() . ' _-])' : '([^[:alpha:]`0' . $output->getColormapEscaped() . '])';
        return preg_replace($expr, '', $inname);
    }

    /**
     * Remove HTML, script and style tags from a string.
     *
     * @param string $str Input HTML
     *
     * @return string Sanitized string
     */
    public static function sanitizeHtml(string $str): string
    {
        $str = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $str);
        $str = preg_replace('/<style[^>]*>.+<\/style[^>]*>/', '', $str);
        $str = preg_replace('/<!--.*-->/', '', $str);
        $str = strip_tags($str);
        return $str;
    }

    /**
     * Ensure a multibyte string is valid for the current charset.
     *
     * @param string $str Input string
     *
     * @return string Sanitized string
     */
    public static function sanitizeMb(string $str): string
    {
        if ($str == '') {
            return '';
        }
        $settings = Settings::getInstance();
        $charset = $settings->getSetting('charset', 'UTF-8');

        while (!mb_check_encoding($str, $charset) && mb_strlen($str, $charset) > 0) {
            $str = mb_substr($str, 0, mb_strlen($str, $charset) - 1, $charset);
        }
        return $str;
    }
}
