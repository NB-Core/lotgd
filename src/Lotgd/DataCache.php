<?php
namespace Lotgd;

/**
 * Lightweight file based data cache helper.
 */
class DataCache
{
    private static array $cache = [];
    private static string $path = '';
    private static bool $checked = false;

    public const FILENAME_PREFIX = 'datacache_';

    public static function get(string $name, int $duration = 60)
    {
        if (!getsetting('usedatacache', 0)) {
            return false;
        }
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }
        $fullname = self::makeCacheTempName($name);
        if (file_exists($fullname) && @filemtime($fullname) > strtotime("-$duration seconds")) {
            $fullfile = @file_get_contents($fullname);
            if ($fullfile > '') {
                self::$cache[$name] = @json_decode($fullfile, true);
                return self::$cache[$name];
            }
        }
        return false;
    }

    public static function put(string $name, $data): bool
    {
        if (!getsetting('usedatacache', 0)) {
            return false;
        }
        $fullname = self::makeCacheTempName($name);
        self::$cache[$name] = $data;
        $fp = fopen($fullname, 'w');
        if ($fp) {
            fwrite($fp, json_encode($data));
            fclose($fp);
            return true;
        }
        return false;
    }

    public static function invalidate(string $name, bool $withPath = true): void
    {
        if (!getsetting('usedatacache', 0)) {
            return;
        }
        $fullname = $withPath ? self::makeCacheTempName($name) : $name;
        if (file_exists($fullname)) {
            unlink($fullname);
        }
        if (!$withPath) {
            unset(self::$cache[$name]);
        }
    }

    public static function massInvalidate(string $name = ''): void
    {
        if (!getsetting('usedatacache', 0)) {
            return;
        }
        $name = self::FILENAME_PREFIX . $name;
        if (self::$path == '') {
            self::$path = getsetting('datacachepath', '/tmp');
        }
        $dir = dir(self::$path);
        while (false !== ($file = $dir->read())) {
            if (strpos($file, $name) !== false) {
                self::invalidate(self::$path . '/' . $file, false);
            }
        }
        $dir->close();
    }

    private static function makeCacheTempName(string $name): string
    {
        if (self::$path == '') {
            self::$path = getsetting('datacachepath', '/tmp');
        }
        $name = rawurlencode($name);
        $name = str_replace('_', '-', $name);
        $name = self::FILENAME_PREFIX . preg_replace("'[^A-Za-z0-9.-]'", '', $name);
        $fullname = self::$path . '/' . $name;
        $fullname = preg_replace("'//'", '/', $fullname);
        $fullname = preg_replace("'\\\\'", '\\', $fullname);
        if (!self::$checked) {
            self::$checked = true;
        }
        return $fullname;
    }
}
