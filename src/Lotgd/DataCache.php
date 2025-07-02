<?php
namespace Lotgd;


/**
 * Lightweight file based data cache helper.
 */

class DataCache
{
    private static array $cache = [];
    private static string $path = '';
    private static bool $checkedOld = false;

    public static function datacache(string $name, int $duration = 60)
    {
        global $datacache;
        if (getsetting('usedatacache', 0)) {
            if (isset(self::$cache[$name])) {
                return self::$cache[$name];
            }
            $fullname = self::makecachetempname($name);
            if (file_exists($fullname) && @filemtime($fullname) > strtotime("-$duration seconds")) {
                $fullfile = @file_get_contents($fullname);
                if ($fullfile > '') {
                    self::$cache[$name] = @json_decode($fullfile, true);
                    return self::$cache[$name];
                }
                return false;
            }
        }
        return false;
    }

    public static function updatedatacache(string $name, $data)
    {
        if (getsetting('usedatacache', 0)) {
            $fullname = self::makecachetempname($name);
            self::$cache[$name] = $data;
            $fp = fopen($fullname, 'w');
            if ($fp) {
                fwrite($fp, json_encode($data));
                fclose($fp);
            }
            return true;
        }
        return false;
    }

    public static function invalidatedatacache(string $name, bool $withpath = true)
    {
        if (getsetting('usedatacache', 0)) {
            $fullname = $withpath ? self::makecachetempname($name) : $name;
            if (file_exists($fullname)) {
                unlink($fullname);
            }
            if (!$withpath) {
                unset(self::$cache[$name]);
            }
        }
    }

    public static function massinvalidate(string $name = '')
    {
        if (getsetting('usedatacache', 0)) {
            $name = DATACACHE_FILENAME_PREFIX . $name;
            if (self::$path == '') {
                self::$path = getsetting('datacachepath', '/tmp');
            }
            $dir = dir(self::$path);
            while (false !== ($file = $dir->read())) {
                if (strpos($file, $name) !== false) {
                    self::invalidatedatacache(self::$path . '/' . $file, false);
                }
            }
            $dir->close();
        }
    }

    public static function makecachetempname(string $name)
    {
        if (self::$path == '') {
            self::$path = getsetting('datacachepath', '/tmp');
        }
        $name = rawurlencode($name);
        $name = str_replace('_', '-', $name);
        $name = DATACACHE_FILENAME_PREFIX . preg_replace("'[^A-Za-z0-9.-]'", '', $name);
        $fullname = self::$path . '/' . $name;
        $fullname = preg_replace("'//'", '/', $fullname);
        $fullname = preg_replace("'\\\\'", '\\', $fullname);

        if (!self::$checkedOld) {
            self::$checkedOld = true;
            // cleanup old caches intentionally skipped
        }
        return $fullname;
    }
}
