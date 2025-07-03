<?php
namespace Lotgd;

/**
 * Helper methods for working with output templates.
 */
class Template
{
    public static function templatereplace($itemname, $vals = false)
    {
        global $template;
        if (!isset($template[$itemname])) {
            output("`bWarning:`b The `i%s`i template part was not found!`n", $itemname);
        }
        $out = $template[$itemname];
        if (!is_array($vals)) {
            return $out;
        }
        foreach ($vals as $key => $val) {
            if (strpos($out, "{".$key."}") === false) {
                output("`bWarning:`b the `i%s`i piece was not found in the `i%s`i template part! (%s)`n", $key, $itemname, $out);
                $out .= $val;
            } else {
                $out = str_replace("{".$key."}", $val, $out);
            }
        }
        return $out;
    }

    public static function prepare_template($force = false)
    {
        if (!$force) {
            if (defined('TEMPLATE_IS_PREPARED')) {
                return;
            }
            define('TEMPLATE_IS_PREPARED', true);
        }

        global $templatename, $templatemessage, $template, $session, $y, $z, $y2, $z2, $copyright, $lc, $x, $templatetags, $_defaultskin;
        if (!isset($_COOKIE['template'])) {
            $_COOKIE['template'] = '';
        }
        $templatename = '';
        $templatemessage = '';
        if ($_COOKIE['template'] != '') {
            $templatename = $_COOKIE['template'];
        }
        if ($templatename == '' || !file_exists("templates/$templatename")) {
            $templatename = getsetting('defaultskin', $_defaultskin);
        }
        if ($templatename == '' || !file_exists("templates/$templatename")) {
            $templatename = $_defaultskin;
        }
        $template = loadtemplate($templatename);
    }
}

