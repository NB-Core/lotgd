<?php

use Lotgd\Settings;

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
        return '';
    }
    return $settings->getSetting($settingname, $default);
}
