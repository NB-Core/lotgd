<?php

use Lotgd\Settings;
use Lotgd\MySQL\Database;

function savesetting($settingname, $value)
{
    global $settings;
    if (!($settings instanceof Settings)) {
        $settings = new Settings('settings');
    }
    $settings->saveSetting($settingname, $value);
}

function loadsettings()
{
    global $settings;
    // settings are loaded on demand
}

function clearsettings()
{
    global $settings;
    if ($settings instanceof Settings) {
        $settings->clearSettings();
    }
    unset($settings);
    $settings = new Settings('settings');
}

function getsetting($settingname, $default)
{
    global $settings;
    if (!($settings instanceof Settings)) {
        if (!Database::tableExists(Database::prefix('settings'))) {
            return $default;
        }

        $settings = new Settings('settings');
    }

    return $settings->getSetting($settingname, $default);
}

function get_admin_email($default = 'postmaster@localhost')
{
    global $settings;
    if (!($settings instanceof Settings)) {
        $settings = new Settings('settings');
    }

    return (string) $settings->getSetting('gameadminemail', $default);
}
