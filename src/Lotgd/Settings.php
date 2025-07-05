<?php
namespace Lotgd;
use Lotgd\MySQL\Database;
use Lotgd\DataCache;
/**
 * Lightweight wrapper around the settings table.
 */
class Settings
{
    private string $tablename;
    private $settings = null;

    public function __construct($tablename = false)
    {
        $this->tablename = $tablename === false ? db_prefix('settings') : db_prefix($tablename);
        $this->settings = null;
        $this->loadSettings();
    }

    public function saveSetting($settingname, $value): bool
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
        db_query($sql);
        $this->settings[$settingname] = $value;
        DataCache::invalidatedatacache('game' . $this->tablename);
        return db_affected_rows() > 0;
    }

    public function loadSettings(): void
    {
        if (!is_array($this->settings)) {
            $this->settings = DataCache::datacache('game' . $this->tablename);
            if (!is_array($this->settings)) {
                $this->settings = [];
                $sql = 'SELECT * FROM ' . $this->tablename;
                $result = db_query($sql);
                while ($row = db_fetch_assoc($result)) {
                    $this->settings[$row['setting']] = $row['value'];
                }
                db_free_result($result);
                DataCache::updatedatacache('game' . $this->tablename, $this->settings);
            }
        }
    }

    public function clearSettings(): void
    {
        DataCache::invalidatedatacache('game' . $this->tablename);
        $this->settings = null;
    }

    public function getSetting($settingname, $default = false)
    {
        global $DB_USEDATACACHE, $DB_DATACACHEPATH;
        if ($settingname == 'usedatacache') {
            return $DB_USEDATACACHE;
        } elseif ($settingname == 'datacachepath') {
            return $DB_DATACACHEPATH;
        }
        if (!isset($this->settings[$settingname])) {
            $this->loadSettings();
        } else {
            return $this->settings[$settingname];
        }
        if (!isset($this->settings[$settingname])) {
            if (file_exists("config/" . $this->tablename . ".php")) {
                require "config/" . $this->tablename . ".php";
            }
            if ($default === false) {
                $setDefault = $defaults[$settingname] ?? '';
            } else {
                $setDefault = $default;
            }
            $this->saveSetting($settingname, $setDefault);
            return $setDefault;
        }
        return $this->settings[$settingname];
    }

    public function getArray(): array
    {
        return (array) $this->settings;
    }
}
