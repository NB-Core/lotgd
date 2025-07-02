<?php
use Lotgd\Settings;

function savesetting($settingname, $value)
{
    global $settings;
    if (!is_a($settings, 'settings')) {
        $settings = new settings('settings');
    }
    $settings->saveSetting($settingname, $value);
}

function loadsettings()
{
    global $settings;
}

function clearsettings()
{
    global $settings;
    if (is_a($settings, 'settings')) {
        $settings->clearSettings();
    }
    unset($settings);
    $settings = new settings('settings');
}

function getsetting($settingname, $default)
{
    global $settings;
    if (!is_a($settings, 'settings')) {
        return '';
    }
    return $settings->getSetting($settingname, $default);
}
