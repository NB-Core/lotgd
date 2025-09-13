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
    private static ?self $instance = null;
    private static array $cache = [];
    private static string $path = '';
    private static bool $checkedOld = false;

    /**
     * Retrieve the global DataCache instance.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Retrieve an entry from the filesystem cache.
     *
     * @param string $name     Cache key
     * @param int    $duration Seconds to keep entry
     *
     * @return mixed Cached data or false when not found
     */
    public function datacache(string $name, int $duration = 60): mixed
    {
        if (defined('DB_NODB') && DB_NODB) {
            return false;
        }

        if (! Settings::hasInstance()) {
            return false;
        }
        $settings = Settings::getInstance();
        if (! $settings->getSetting('usedatacache', 0)) {
            return false;
        }
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }
        $fullname = $this->makecachetempname($name);
        if (file_exists($fullname) && @filemtime($fullname) > strtotime("-$duration seconds")) {
            $fullfile = @file_get_contents($fullname);
            if ($fullfile > '') {
                self::$cache[$name] = @json_decode($fullfile, true);
                return self::$cache[$name];
            }
            return false;
        }
        return false;
    }

    /**
     * Store a value in the data cache.
     *
     * @param string $name Cache key
     * @param mixed  $data Data to store
     */
    public function updatedatacache(string $name, mixed $data): bool
    {
        if (defined('DB_NODB') && DB_NODB) {
            return false;
        }

        if (! Settings::hasInstance()) {
            return false;
        }
        $settings = Settings::getInstance();
        if ($settings->getSetting('usedatacache', 0)) {
            $base = $settings->getSetting('datacachepath', '/tmp');
            if (file_exists($base) && !is_dir($base)) {
                return false;
            }
            $parent = dirname($base);
            if (!is_dir($parent)) {
                return false;
            }
            if (!is_dir($base)) {
                if (!@mkdir($base, 0777, true) && !is_dir($base)) {
                    return false;
                }
            }

            self::$path = $base;
            $fullname = $this->makecachetempname($name);
            self::$cache[$name] = $data;

            $encoded = json_encode($data);
            if ($encoded === false) {
                return false;
            }

            $fp = @fopen($fullname, 'w');
            if ($fp === false) {
                return false;
            }

            $written = fwrite($fp, $encoded);
            fclose($fp);

            return $written !== false;
        }

        return false;
    }

    /**
     * Remove an entry from the cache.
     */
    public function invalidatedatacache(string $name, bool $withpath = true): void
    {
        global $session;

        if (defined('DB_NODB') && DB_NODB) {
            return;
        }

        if (! Settings::hasInstance()) {
            return;
        }
        $settings = Settings::getInstance();
        if ($settings->getSetting('usedatacache', 0)) {
            $fullname = $withpath ? $this->makecachetempname($name) : $name;
            if (is_file($fullname) && ! @unlink($fullname)) {
                GameLog::log(
                    'Failed to remove cache file ' . $fullname,
                    'cache',
                    false,
                    $session['user']['acctid'] ?? 0
                );
            }
            if (! $withpath) {
                unset(self::$cache[$name]);
            }
        }
    }

    /**
     * Invalidate all cache entries matching prefix.
     */
    public function massinvalidate(string $name = ''): void
    {
        if (defined('DB_NODB') && DB_NODB) {
            return;
        }

        if (! Settings::hasInstance()) {
            return;
        }
        $settings = Settings::getInstance();
        if ($settings->getSetting('usedatacache', 0)) {
            $name = DATACACHE_FILENAME_PREFIX . $name;
            if (self::$path == '') {
                self::$path = $settings->getSetting('datacachepath', '/tmp');
            }
            $dir = dir(self::$path);
            while (false !== ($file = $dir->read())) {
                if (strpos($file, $name) !== false) {
                    $this->invalidatedatacache(self::$path . '/' . $file, false);
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
    public function makecachetempname(string $name): string
    {
        if (defined('DB_NODB') && DB_NODB) {
            return '';
        }

        if (! Settings::hasInstance()) {
            return '';
        }
        $settings = Settings::getInstance();
        if (self::$path == '') {
            self::$path = $settings->getSetting('datacachepath', '/tmp');
        }
        $name = rawurlencode($name);
        $name = str_replace('_', '-', $name);
        $name = preg_replace("'[^A-Za-z0-9.-]'", '', $name);

        if (strlen($name) > 200) {
            // Preserve a short prefix but replace the rest with a hash when the key is too long
            $name = substr($name, 0, 40) . '-' . sha1($name);
        }

        $name = DATACACHE_FILENAME_PREFIX . $name;
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
