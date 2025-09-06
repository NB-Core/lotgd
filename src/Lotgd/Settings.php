<?php

declare(strict_types=1);

/**
 * Lightweight wrapper around the settings table.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\DataCache;
use Doctrine\DBAL\Exception\TableNotFoundException;

class Settings
{
    private static ?self $instance = null;
    private string $tablename;
    /** @var array|null */
    private $settings = null;
    /** Flag to prevent concurrent loading */
    private bool $loading = false;

    /**
     * Initialize the settings handler.
     *
     * @param string $tablename Optional table name
     */
    public function __construct(string $tablename = 'settings')
    {
        $this->tablename = Database::prefix($tablename);
        $this->settings = null;

        self::$instance = $this;
        $GLOBALS['settings'] = $this;

        if (defined('DB_NODB') && DB_NODB) {
            return;
        }

        $this->loadSettings();
    }

    /**
     * Retrieve the global Settings instance.
     */
    public static function getInstance(): self
    {
        if (isset($GLOBALS['settings']) && $GLOBALS['settings'] instanceof self) {
            self::$instance = $GLOBALS['settings'];
        } elseif (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public static function hasInstance(): bool
    {
        return self::$instance instanceof self
            || (isset($GLOBALS['settings']) && $GLOBALS['settings'] instanceof self);
    }

    /**
     * Persist a setting value.
     *
     * @param string|int $settingname Setting identifier
     * @param mixed      $value       Value to store
     *
     * @return bool True on success
     */
    public function saveSetting(string|int $settingname, mixed $value): bool
    {
        if (!Database::tableExists($this->tablename)) {
            return false;
        }

        $this->loadSettings();

        $settingValue = is_string($value) ? '"' . addslashes($value) . '"' : $value;
        $settingName = is_string($settingname) ? '"' . addslashes($settingname) . '"' : $settingname;

        $sql = "INSERT INTO " . $this->tablename .
            " (setting,value) VALUES ($settingName,$settingValue) " .
            "ON DUPLICATE KEY UPDATE value=VALUES(value)";

        Database::query($sql);
        $this->settings[$settingname] = $value;
        DataCache::getInstance()->invalidatedatacache('game' . $this->tablename);
        return Database::affectedRows() > 0;
    }

    /**
     * Load all settings from the database.
     *
     * @return void
     */
    public function loadSettings(): void
    {
        if ($this->loading) {
            return;
        }
        $this->loading = true;

        try {
            if (!is_array($this->settings)) {
                if (!Database::tableExists($this->tablename)) {
                    return;
                }

                $this->settings = DataCache::getInstance()->datacache('game' . $this->tablename);
                if (!is_array($this->settings)) {
                    $this->settings = [];

                    try {
                        $sql = 'SELECT * FROM ' . $this->tablename;
                        $result = Database::query($sql);
                        while ($row = Database::fetchAssoc($result)) {
                            $this->settings[$row['setting']] = $row['value'];
                        }
                        Database::freeResult($result);
                    } catch (TableNotFoundException $e) {
                        return;
                    }

                    DataCache::getInstance()->updatedatacache('game' . $this->tablename, $this->settings);
                }
            }
        } finally {
            $this->loading = false;
        }
    }

    /**
     * Clear cached settings forcing a reload on next access.
     *
     * @return void
     */
    public function clearSettings(): void
    {
        DataCache::getInstance()->invalidatedatacache('game' . $this->tablename);
        $this->settings = null;
    }

    /**
     * Retrieve a specific setting value.
     *
     * @param string|int $settingname Name of the setting
     * @param mixed      $default     Default when missing
     *
     * @return mixed Setting value
     */
    public function getSetting(string|int $settingname, mixed $default = false): mixed
    {
        global $config;
        $defaults = [];

        if (defined('DB_NODB') && DB_NODB) {
            return $default === false ? ($defaults[$settingname] ?? '') : $default;
        }
        if (!is_array($config)) {
            $root = dirname(__DIR__, 2);
            $path = realpath($root . '/dbconnect.php');
            $config = $path ? require $path : [];
        }
        if ($settingname == 'usedatacache') {
            return $config['DB_USEDATACACHE'] ?? 0;
        } elseif ($settingname == 'datacachepath') {
            return $config['DB_DATACACHEPATH'] ?? '';
        }
        if (!isset($this->settings[$settingname])) {
            $this->loadSettings();
            if (!isset($this->settings[$settingname])) {
                if ($settingname === 'charset') {
                    $this->saveSetting('charset', 'UTF-8');

                    return 'UTF-8';
                }
                if (file_exists('config/' . $this->tablename . '.php')) {
                    require 'config/' . $this->tablename . '.php';
                }
                if ($default === false) {
                    $value = $defaults[$settingname] ?? '';
                } else {
                    $value = $default;
                }
                $this->saveSetting($settingname, $value);
            } else {
                $value = $this->settings[$settingname];
            }
        } else {
            $value = $this->settings[$settingname];
        }

        if ($settingname === 'charset' && $value !== 'UTF-8') {
            $this->saveSetting('charset', 'UTF-8');

            return 'UTF-8';
        }

        return $value;
    }

    /**
     * Get all loaded settings as an array.
     *
     * @return array List of settings
     */
    public function getArray(): array
    {
        return (array) $this->settings;
    }
}
