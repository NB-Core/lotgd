<?php

// Legacy wrapper for Sanitize class
// translator ready
// addnews ready
// mail ready

use Lotgd\Sanitize;

function sanitize($in)
{
    return Sanitize::sanitize($in);
}

function newline_sanitize($in)
{
    return Sanitize::newlineSanitize($in);
}

function color_sanitize($in)
{
    return Sanitize::colorSanitize($in);
}

function comment_sanitize($in)
{
    return Sanitize::commentSanitize($in);
}

function logdnet_sanitize($in)
{
    return Sanitize::logdnetSanitize($in);
}

function full_sanitize($in)
{
    return Sanitize::fullSanitize($in);
}

function cmd_sanitize($in)
{
    return Sanitize::cmdSanitize($in);
}

function comscroll_sanitize($in)
{
    return Sanitize::comscrollSanitize($in);
}

function prevent_colors($in)
{
    return Sanitize::preventColors($in);
}

function translator_uri($in)
{
    return Sanitize::translatorUri($in);
}

function translator_page($in)
{
    return Sanitize::translatorPage($in);
}

function modulename_sanitize($in)
{
    return Sanitize::modulenameSanitize($in);
}

function stripslashes_array($given)
{
    return Sanitize::stripslashesArray($given);
}

function sanitize_name($spaceallowed, $inname)
{
    return Sanitize::sanitizeName($spaceallowed, $inname);
}

function sanitize_colorname($spaceallowed, $inname, $admin = false)
{
    return Sanitize::sanitizeColorname($spaceallowed, $inname, $admin);
}

function sanitize_html($str)
{
    return Sanitize::sanitizeHtml($str);
}

function sanitize_mb($str)
{
    return Sanitize::sanitizeMb($str);
}
