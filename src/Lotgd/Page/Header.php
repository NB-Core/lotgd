<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\PageParts;
use Lotgd\Translator;
use Lotgd\Template;
use Lotgd\TwigTemplate;
use Lotgd\Sanitize;
use Lotgd\HolidayText;
use Lotgd\Buffs;

class Header
{
    public static function pageHeader(...$args): void
    {
        global $header, $SCRIPT_NAME, $session, $template, $settings;

        PageParts::$noPopups['login.php'] = true;
        PageParts::$noPopups['motd.php'] = true;
        PageParts::$noPopups['index.php'] = true;
        PageParts::$noPopups['create.php'] = true;
        PageParts::$noPopups['about.php'] = true;
        PageParts::$noPopups['mail.php'] = true;

        Translator::translatorSetup();
        Template::prepareTemplate();
        if (isset($SCRIPT_NAME)) {
            $script = substr($SCRIPT_NAME, 0, strrpos($SCRIPT_NAME, '.'));
            if ($script) {
                if (!array_key_exists($script, PageParts::$runHeaders)) {
                    PageParts::$runHeaders[$script] = false;
                }
                if (!PageParts::$runHeaders[$script]) {
                    if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
                        modulehook('everyheader', ['script' => $script]);
                    }
                    PageParts::$runHeaders[$script] = true;
                    if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
                        modulehook("header-$script");
                    }
                }
            }
        }

        $arguments = func_get_args();
        if (!$arguments || count($arguments) === 0) {
            $arguments = ['Legend of the Green Dragon'];
        }
        $title = call_user_func_array([Translator::class, 'sprintfTranslate'], $arguments);
        $title = Sanitize::sanitize(HolidayText::holidayize($title, 'title'));
        Buffs::calculateBuffFields();

        $lang     = defined('LANGUAGE') ? LANGUAGE : getsetting('defaultlanguage', 'en');
        $metaDesc = getsetting('meta_description', 'A browser game using the Legend of the Green Dragon Engine');

        if (TwigTemplate::isActive()) {
            PageParts::$twigVars['title'] = $title;
            PageParts::$twigVars['lang']  = $lang;
            PageParts::$twigVars['meta_description'] = $metaDesc;
        } else {
            $header = $template['header'];
            $header = str_replace('{title}', $title, $header);
            $header = str_replace('{lang}', $lang, $header);
            $header = str_replace('{meta_description}', $metaDesc, $header);
        }
        $header .= Translator::tlbuttonPop();
        if (isset($settings) && $settings->getSetting('debug', 0)) {
            $session['debugstart'] = microtime();
        }
    }

    public static function popupHeader(...$args): void
    {
        global $header, $template;

        \Lotgd\Translator::setup();
        Template::prepare();

        modulehook('header-popup');

        $arguments = func_get_args();
        if (!$arguments || count($arguments) === 0) {
            $arguments = ['Legend of the Green Dragon'];
        }
        $title = \Lotgd\Translator::translateWithSprintf(...$arguments);
        $title = HolidayText::holidayize($title, 'title');

        $lang     = defined('LANGUAGE') ? LANGUAGE : getsetting('defaultlanguage', 'en');
        $metaDesc = getsetting('meta_description', 'A browser game using the Legend of the Green Dragon Engine');

        if (TwigTemplate::isActive()) {
            PageParts::$twigVars['title'] = $title;
            PageParts::$twigVars['lang']  = $lang;
            PageParts::$twigVars['meta_description'] = $metaDesc;
            return;
        }

        $header = $template['popuphead'];
        $header = str_replace('{title}', $title, $header);
        $header = str_replace('{lang}', $lang, $header);
        $header = str_replace('{meta_description}', $metaDesc, $header);
    }
}
