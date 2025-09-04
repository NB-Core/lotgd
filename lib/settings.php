<?php

use Lotgd\Settings;
use Lotgd\MySQL\Database;

function savesetting($settingname, $value)
{
    Settings::getInstance()->saveSetting($settingname, $value);
}

function loadsettings()
{
    // settings are loaded on demand
}

function clearsettings()
{
    Settings::getInstance()->clearSettings();
    Settings::setInstance(new Settings());
}

function getsetting($settingname, $default)
{
    if (!Database::tableExists(Database::prefix('settings'))) {
        return $default;
    }

    return Settings::getInstance()->getSetting($settingname, $default);
}

function get_admin_email($default = 'postmaster@localhost')
{
    return (string) Settings::getInstance()->getSetting('gameadminemail', $default);
}
