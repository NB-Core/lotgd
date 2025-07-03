<?php
namespace Lotgd;

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
            $sql = "INSERT INTO " . $this->tablename . " (setting,value) VALUES (\"" . addslashes($settingname) . "\",\"" . addslashes($value) . "\")";
        } elseif (isset($this->settings[$settingname])) {
            $sql = "UPDATE " . $this->tablename . " SET value=\"" . addslashes($value) . "\" WHERE setting=\"" . addslashes($settingname) . "\"";
        } else {
            return false;
        }
        db_query($sql);
        $this->settings[$settingname] = $value;
        invalidatedatacache('game' . $this->tablename);
        return db_affected_rows() > 0;
    }

    public function loadSettings(): void
    {
        if (!is_array($this->settings)) {
            $this->settings = datacache('game' . $this->tablename);
            if (!is_array($this->settings)) {
                $this->settings = [];
                $sql = 'SELECT * FROM ' . $this->tablename;
                $result = db_query($sql);
                while ($row = db_fetch_assoc($result)) {
                    $this->settings[$row['setting']] = $row['value'];
                }
                db_free_result($result);
                updatedatacache('game' . $this->tablename, $this->settings);
            }
        }
    }

    public function clearSettings(): void
    {
        invalidatedatacache('game' . $this->tablename);
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
