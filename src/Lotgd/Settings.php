<?php

declare(strict_types=1);

/**
 * Lightweight wrapper around the settings table.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\DataCache;

class Settings
{
    private string $tablename;
    /** @var array|null */
    private $settings = null;

    /**
     * Initialize the settings handler.
     *
     * @param string|false $tablename Optional table name
     */
    public function __construct(string|false $tablename = false)
    {
        $this->tablename = $tablename === false ? Database::prefix('settings') : Database::prefix($tablename);
        $this->settings = null;
        $this->loadSettings();
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
        $this->loadSettings();
        if (!isset($this->settings[$settingname]) && $value) {
            $settingValue = is_string($value) ? '"' . addslashes($value) . '"' : $value;
            $settingName = is_string($settingname) ? '"' . addslashes($settingname) . '"' : $settingname;
            $sql = "INSERT INTO " . $this->tablename . " (setting,value) VALUES ($settingName,$settingValue)";
        } elseif (isset($this->settings[$settingname])) {
            $settingValue = is_string($value) ? '"' . addslashes($value) . '"' : $value;
            $settingName = is_string($settingname) ? '"' . addslashes($settingname) . '"' : $settingname;
            $sql = "UPDATE " . $this->tablename . " SET value=$settingValue WHERE setting=$settingName";
        } else {
            return false;
        }
        Database::query($sql);
        $this->settings[$settingname] = $value;
        DataCache::invalidatedatacache('game' . $this->tablename);
        return Database::affectedRows() > 0;
    }

    /**
     * Load all settings from the database.
     *
     * @return void
     */
    public function loadSettings(): void
    {
        if (!is_array($this->settings)) {
            $this->settings = DataCache::datacache('game' . $this->tablename);
            if (!is_array($this->settings)) {
                $this->settings = [];
                $sql = 'SELECT * FROM ' . $this->tablename;
                $result = Database::query($sql);
                while ($row = Database::fetchAssoc($result)) {
                    $this->settings[$row['setting']] = $row['value'];
                }
                Database::freeResult($result);
                DataCache::updatedatacache('game' . $this->tablename, $this->settings);
            }
        }
    }

    /**
     * Clear cached settings forcing a reload on next access.
     *
     * @return void
     */
    public function clearSettings(): void
    {
        DataCache::invalidatedatacache('game' . $this->tablename);
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
