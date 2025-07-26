<?php

declare(strict_types=1);

namespace Lotgd;

if (!defined('DATACACHE_FILENAME_PREFIX')) {
    define('DATACACHE_FILENAME_PREFIX', 'datacache-');
}

/**
 * Lightweight file based data cache helper.
 */

class DataCache
{
    private static array $cache = [];
    private static string $path = '';
    private static bool $checkedOld = false;

    /**
     * Retrieve an entry from the filesystem cache.
     *
     * @param string $name     Cache key
     * @param int    $duration Seconds to keep entry
     *
     * @return mixed Cached data or false when not found
     */
    public static function datacache(string $name, int $duration = 60): mixed
    {
        global $datacache, $settings;
        if (!isset($settings)) {
            return false; // not yet setup most likely
        }

        if ($settings->getSetting('usedatacache', 0)) {
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

    /**
     * Store a value in the data cache.
     *
     * @param string $name Cache key
     * @param mixed  $data Data to store
     */
    public static function updatedatacache(string $name, mixed $data): bool
    {
        global $settings;
        if (!isset($settings)) {
            return false;
        }
        if ($settings->getSetting('usedatacache', 0)) {
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

    /**
     * Remove an entry from the cache.
     */
    public static function invalidatedatacache(string $name, bool $withpath = true): void
    {
        global $settings;
        if (!isset($settings)) {
            return;
        }
        if ($settings->getSetting('usedatacache', 0)) {
            $fullname = $withpath ? self::makecachetempname($name) : $name;
            if (file_exists($fullname)) {
                unlink($fullname);
            }
            if (!$withpath) {
                unset(self::$cache[$name]);
            }
        }
    }

    /**
     * Invalidate all cache entries matching prefix.
     */
    public static function massinvalidate(string $name = ''): void
    {
        global $settings;
        if (!isset($settings)) {
            return;
        }
        if ($settings->getSetting('usedatacache', 0)) {
            $name = DATACACHE_FILENAME_PREFIX . $name;
            if (self::$path == '') {
                self::$path = $settings->getSetting('datacachepath', '/tmp');
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

    /**
     * Build full path for a cache entry.
     *
     * @return string Cache filename
     */
    public static function makecachetempname(string $name): string
    {
        global $settings;
        if (!isset($settings)) {
            return '';
        }
        if (self::$path == '') {
            self::$path = $settings->getSetting('datacachepath', '/tmp');
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
