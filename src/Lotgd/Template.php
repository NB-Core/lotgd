<?php
declare(strict_types=1);

namespace Lotgd;

/**
 * Helper methods for working with output templates.
 */
class Template
{
    /**
     * Replace placeholders within a template section.
     *
     * @param string      $itemname Template section name
     * @param array|false $vals     Replacement values
     *
     * @return string Processed template part
     */
    public static function templateReplace(string $itemname, array|false $vals = false): string
    {
        // When Twig is active, try rendering a matching partial
        if (TwigTemplate::isActive()) {
            $twigFile = __DIR__ . '/../../' . TwigTemplate::getPath() . "/{$itemname}.twig";
            if (file_exists($twigFile)) {
                return TwigTemplate::render("{$itemname}.twig", is_array($vals) ? $vals : []);
            }
        }

        global $template;
        if (!isset($template[$itemname])) {
            // If the template part is not found, it's usually not a bad thing. So comment in if you have issues with missing template parts.
            // output("`bWarning:`b The `i%s`i template part was not found!`n", $itemname);
            return '';
        }

        $out = $template[$itemname];
        if (!is_array($vals)) {
            return $out;
        }

        foreach ($vals as $key => $val) {
            if (strpos($out, '{' . $key . '}') === false) {
                // If the template part is not found, it's usually not a bad thing. So comment in if you have issues with missing template parts.
                // output("`bWarning:`b the `i%s`i piece was not found in the `i%s`i template part! (%s)`n", $key, $itemname, $out);
                // $out .= $val;
            } else {
                $out = str_replace('{' . $key . '}', $val, $out);
            }
        }

        return $out;
    }

    /**
     * Load the active template into memory.
     *
     * @param bool $force Force reload even if already loaded
     *
     * @return void
     */
    public static function prepareTemplate(bool $force = false): void
    {
        global $settings;
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
        $templateType = '';
        $templatemessage = '';
        if ($_COOKIE['template'] != '') {
            $templatename = $_COOKIE['template'];
        }
        if (strpos($templatename, ':') !== false) {
            [$templateType, $templatename] = explode(':', $templatename, 2);
        }
        if ($templatename == '' || (!file_exists("templates/$templatename") && !is_dir("templates_twig/$templatename"))) {
            if (isset($settings) && $settings instanceof Settings) {
                // If the settings object is available, use it to get the default skin
                $templatename = $settings->getSetting('defaultskin', 'jade.htm');
            } else {
                // Fallback to a hardcoded default skin if settings are not available
                $templatename = 'jade.htm';
            }
        }
        if ($templatename == '' || (!file_exists("templates/$templatename") && !is_dir("templates_twig/$templatename"))) {
            $templatename = $_defaultskin;
        }

        if ($templateType === 'twig' || is_dir("templates_twig/$templatename")) {
            // Initialize Twig environment for modern templates
            TwigTemplate::init($templatename, $settings instanceof Settings ? $settings : null);
            $template = [];
        } else {
            $template = self::loadTemplate($templatename);
        }
    }

    /**
     * Ensure a template name is prefixed with its type.
     *
     * @param string $template Template name, with or without prefix
     *
     * @return string Prefixed template name
     */
    public static function addTypePrefix(string $template): string
    {
        if (str_contains($template, ':')) {
            return $template;
        }

        if (is_dir("templates_twig/$template")) {
            return 'twig:' . $template;
        }

        return 'legacy:' . $template;
    }

    /**
     * Load a template file and split it into sections.
     *
     * If the template doesn't exist, uses the admin-defined default template
     * and then falls back to jade.htm.
     *
     * @param string $templatename Template file name
     *
     * @return array Parsed template array
     */
    public static function loadTemplate(string $templatename): array
    {
	    if ($templatename=="" || !file_exists("templates/$templatename"))
		    $templatename=getsetting("defaultskin",$_defaultskin);
	    if ($templatename=="" || !file_exists("templates/$templatename"))
		    $templatename=$_defaultskin;
	    $fulltemplate = file_get_contents("templates/$templatename");
	    $fulltemplate = explode("<!--!",$fulltemplate);
	    foreach ($fulltemplate as $val) {
           if ($val == "") continue; // Skip empty sections
		    $fieldname=substr($val,0,strpos($val,"-->"));
		    if ($fieldname!=""){
			    $template[$fieldname]=substr($val,strpos($val,"-->")+3);
			    if (!defined("IS_INSTALLER")) modulehook("template-{$fieldname}",
					    array("content"=>$template[$fieldname]));
		    }
	    }
	    return $template;
    }

}

