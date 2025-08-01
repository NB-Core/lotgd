<?php

declare(strict_types=1);

/**
 * Helper methods to fetch remote URLs using different mechanisms.
 */

namespace Lotgd;

use Lotgd\Settings;

class PullUrl
{
    private static function curl(string $url)
    {
        $ch = curl_init();
        if (!$ch) {
            return false;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $val = 5;
        if (defined('DB_CONNECTED') && DB_CONNECTED === true) {
            global $settings;
            if ($settings instanceof Settings) {
                $val = $settings->getSetting('curltimeout', 5);
            }
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $val);
        curl_setopt($ch, CURLOPT_TIMEOUT, $val);
        $ret = curl_exec($ch);
        curl_close($ch);
        $val = explode("\n", $ret);
        $total = count($val);
        $cur = 0;
        foreach ($val as $a) {
            $cur++;
            $done[] = $a . ($cur != $total ? "\n" : '');
        }
        return $done;
    }

    private static function sock(string $url)
    {
        $a = preg_match('!http://([^/:]+)(:[0-9]+)?(/.*)!', $url, $matches);
        if (!$a) {
            return false;
        }
        $host = $matches[1];
        $port = (int) $matches[2];
        if ($port == 0) {
            $port = 80;
        }
        $path = $matches[3];
        $f = @fsockopen($host, $port, $errno, $errstr, 1);
        if (!$f) {
            return false;
        }
        if (function_exists('stream_set_timeout')) {
            stream_set_timeout($f, 1);
        }
        $out = "GET $path HTTP/1.1\r\n";
        $out .= "Host: $host\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($f, $out);
        $skip = 1;
        $done = [];
        while (!feof($f)) {
            $buf = fgets($f, 8192);
            if ($buf == "\r\n" && $skip) {
                $skip = 0;
                continue;
            }
            if (!$skip) {
                $done[] = $buf;
            }
        }
        $info = stream_get_meta_data($f);
        fclose($f);
        if ($info['timed_out']) {
            debug("Call to $url timed out!");
            $done = false;
        }
        return $done;
    }

    /**
     * Retrieve the content of a URL.
     */
    public static function pull(string $url)
    {
        // Prefer file() to avoid open_basedir issues.
        return @file($url);
    }
}
