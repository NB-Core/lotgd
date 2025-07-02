<?php
namespace Lotgd;

class Sanitize
{
    public static function sanitize($in)
    {
        global $output;
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`][0' . $output->get_colormap_escaped() . 'bicnHw]/', '', $in);
        return $out;
    }

    public static function newlineSanitize($in)
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/`n/', '', $in);
        return $out;
    }

    public static function colorSanitize($in)
    {
        if ($in == '' || $in === null) {
            return '';
        }
        global $output;
        $out = preg_replace('/[`][0' . $output->get_colormap_escaped() . 'cbi]/', '', $in);
        return $out;
    }

    public static function commentSanitize($in)
    {
        global $output;
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`](?=[^0' . $output->get_colormap_escaped() . '])/', chr(1) . chr(1), $in);
        $out = str_replace(chr(1), '`', $out);
        return $out;
    }

    public static function logdnetSanitize($in)
    {
        global $output;
        $out = preg_replace('/[`](?=[^0' . $output->get_colormap_escaped() . 'bicn])/', chr(1) . chr(1), $in);
        $out = str_replace(chr(1), '`', $out);
        return $out;
    }

    public static function fullSanitize($in)
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`]./', '', $in);
        return $out;
    }

    public static function cmdSanitize($in)
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace("'[&?]c=[[:digit:]-]+'", '', $in);
        return $out;
    }

    public static function comscrollSanitize($in)
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

    public static function preventColors($in)
    {
        return str_replace('`', '&#0096;', $in);
    }

    public static function translatorUri($in)
    {
        $uri = self::comscrollSanitize($in);
        $uri = self::cmdSanitize($uri);
        if (substr($uri, -1) == '?') {
            $uri = substr($uri, 0, -1);
        }
        return $uri;
    }

    public static function translatorPage($in)
    {
        $page = $in;
        if (strpos($page, '?') !== false) {
            $page = substr($page, 0, strpos($page, '?'));
        }
        return $page;
    }

    public static function modulenameSanitize($in)
    {
        return preg_replace("'[^0-9A-Za-z_]'", '', $in);
    }

    public static function stripslashesArray($given)
    {
        return is_array($given) ? array_map([self::class, 'stripslashesArray'], $given) : stripslashes($given);
    }

    public static function sanitizeName($spaceallowed, $inname)
    {
        $expr = $spaceallowed ? '([^[:alpha:] _-])' : '([^[:alpha:]])';
        return preg_replace($expr, '', $inname);
    }

    public static function sanitizeColorname($spaceallowed, $inname, $admin = false)
    {
        global $output;
        if ($admin && getsetting('allowoddadminrenames', 0)) {
            return $inname;
        }
        $expr = $spaceallowed ? '([^[:alpha:]`0' . $output->get_colormap_escaped() . ' _-])' : '([^[:alpha:]`0' . $output->get_colormap_escaped() . '])';
        return preg_replace($expr, '', $inname);
    }

    public static function sanitizeHtml($str)
    {
        $str = preg_replace('/<script[^>]*>.+<\/script[^>]*>/', '', $str);
        $str = preg_replace('/<style[^>]*>.+<\/style[^>]*>/', '', $str);
        $str = preg_replace('/<!--.*-->/', '', $str);
        $str = strip_tags($str);
        return $str;
    }

    public static function sanitizeMb($str)
    {
        if ($str == '') {
            return '';
        }
        while (!mb_check_encoding($str, getsetting('charset', 'ISO-8859-1')) && strlen($str) > 0) {
            $str = substr($str, 0, strlen($str) - 1);
        }
        return $str;
    }
}
