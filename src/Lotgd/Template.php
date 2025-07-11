<?php
declare(strict_types=1);

namespace Lotgd;

use Lotgd\ServerFunctions;
use Lotgd\Cookies;

/**
 * Helper methods for working with output templates.
 */
class Template
{

    // Set SANITIZATION_REGEX
    private const SANITIZATION_REGEX = '/[^a-zA-Z0-9:_-]/';

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

        global $templatename, $templatemessage, $template, $session, $y, $z, $y2, $z2, $copyright, $lc, $x, $templatetags;
        $templatename = '';
        $templateType = '';
        $templatemessage = '';
        $cookieTemplate = self::getTemplateCookie();
        if ($cookieTemplate !== '') {
            $templatename = $cookieTemplate;
        }
        if (strpos($templatename, ':') !== false) {
            [$templateType, $templatename] = explode(':', $templatename, 2);
        }
        if ($templatename == '' || (!file_exists("templates/$templatename") && !is_dir("templates_twig/$templatename"))) {
            if (isset($settings) && $settings instanceof Settings) {
                // Pull the skin from settings (the distribution ships with DEFAULT_TEMPLATE).
                // Administrators can change this via the 'defaultskin' setting.
                $templatename = $settings->getSetting('defaultskin', DEFAULT_TEMPLATE);
            } else {
                // Use DEFAULT_TEMPLATE when settings are unavailable
                $templatename = DEFAULT_TEMPLATE;
            }
            if (strpos($templatename, ':') !== false) {
                [$templateType, $templatename] = explode(':', $templatename, 2);
            }
        }
        if ($templatename == '' || (!file_exists("templates/$templatename") && !is_dir("templates_twig/$templatename"))) {
            $templatename = DEFAULT_TEMPLATE;
            if (strpos($templatename, ':') !== false) {
                [$templateType, $templatename] = explode(':', $templatename, 2);
            }
        }

        if ($templateType === 'twig' || is_dir("templates_twig/$templatename")) {
            // Initialize Twig environment for Twig templates
            $cachePath = null;
            if ($settings instanceof Settings) {
                $cachePath = $settings->getSetting('datacachepath', '/tmp');
            }
            TwigTemplate::init($templatename, $cachePath);
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
     * Set the template cookie after sanitizing the value.
     *
     * @param string $template User provided template name
     *
     * @return void
     */
    public static function setTemplateCookie(string $template): void
    {
        Cookies::setTemplate($template);
    }

    /**
     * Get the sanitized template cookie value.
     *
     * @return string Sanitized template value or empty string
     */
    public static function getTemplateCookie(): string
    {
        return Cookies::getTemplate();
    }

    /**
     * Retrieve available template identifiers.
     *
     * @return array<string,string> Array of template key => display name
     */
    public static function getAvailableTemplates(): array
    {
        $skins = [];

        $handle = opendir('templates');
        if ($handle !== false) {
            while (false !== ($file = readdir($handle))) {
                if (strpos($file, '.htm') !== false) {
                    $value = 'legacy:' . $file;
                    $skins[$value] = substr($file, 0, strpos($file, '.htm'));
                }
            }
            closedir($handle);
        }

        $handle = opendir('templates_twig');
        if ($handle !== false) {
            while (false !== ($dir = readdir($handle))) {
                if ($dir === '.' || $dir === '..') {
                    continue;
                }
                if (is_dir("templates_twig/$dir")) {
                    $name = $dir;
                    $configPath = "templates_twig/$dir/config.json";
                    if (file_exists($configPath)) {
                        $cfg = json_decode((string) file_get_contents($configPath), true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($cfg['name'])) {
                            $name = $cfg['name'];
                        }
                    }
                    $value = 'twig:' . $dir;
                    $skins[$value] = $name;
                }
            }
            closedir($handle);
        }

        asort($skins, SORT_NATURAL | SORT_FLAG_CASE);

        return $skins;
    }

    /**
     * Check if a template identifier is valid.
     */
    public static function isValidTemplate(string $template): bool
    {
        $template = self::addTypePrefix($template);
        $templates = self::getAvailableTemplates();

        return array_key_exists($template, $templates);
    }

    /**
     * Load a template file and split it into sections.
     *
     * If the template doesn't exist, uses the admin-defined default template
     * (DEFAULT_TEMPLATE) and then falls back to that constant.
     *
     * @param string $templatename Template file name
     *
     * @return array Parsed template array
     */
    public static function loadTemplate(string $templatename): array
    {
            if ($templatename=="" || !file_exists("templates/$templatename"))
                    $templatename=getsetting("defaultskin", DEFAULT_TEMPLATE);
            if ($templatename=="" || !file_exists("templates/$templatename"))
                    $templatename=DEFAULT_TEMPLATE;
	    $fulltemplate = file_get_contents("templates/$templatename");
	    $fulltemplate = explode("<!--!",$fulltemplate);
	    foreach ($fulltemplate as $val) {
           if ($val == "") continue; // Skip empty sections
		    $fieldname=substr($val,0,strpos($val,"-->"));
		    if ($fieldname!=""){
			    $template[$fieldname]=substr($val,strpos($val,"-->")+3);
                            if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
                                modulehook("template-{$fieldname}", ['content' => $template[$fieldname]]);
                            }
		    }
	    }
	    return $template;
    }

}

