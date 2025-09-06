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
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;
use Lotgd\Settings;
use Lotgd\Nav;
use Lotgd\PhpGenericEnvironment;

class Header
{
    public static function pageHeader(...$args): void
    {
        global $session;
        $settings = Settings::getInstance();
        $nav      = Nav::getInstance();

        PageParts::$noPopups['login.php'] = true;
        PageParts::$noPopups['motd.php'] = true;
        PageParts::$noPopups['index.php'] = true;
        PageParts::$noPopups['create.php'] = true;
        PageParts::$noPopups['about.php'] = true;
        PageParts::$noPopups['mail.php'] = true;

        Translator::translatorSetup();
        Template::prepareTemplate();
        $template = Template::getInstance()->getTemplate();
        if (PhpGenericEnvironment::getScriptName() !== '') {
            $script = ScriptName::current();
            if ($script) {
                if (!array_key_exists($script, PageParts::$runHeaders)) {
                    PageParts::$runHeaders[$script] = false;
                }
                if (!PageParts::$runHeaders[$script]) {
                    if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
                        HookHandler::hook('everyheader', ['script' => $script]);
                    }
                    PageParts::$runHeaders[$script] = true;
                    if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
                        HookHandler::hook("header-$script");
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

        $lang     = defined('LANGUAGE') ? LANGUAGE : $settings->getSetting('defaultlanguage', 'en');
        $metaDesc = $settings->getSetting('meta_description', 'A browser game using the Legend of the Green Dragon Engine');

        if (TwigTemplate::isActive()) {
            PageParts::$twigVars['title'] = $title;
            PageParts::$twigVars['lang']  = $lang;
            PageParts::$twigVars['meta_description'] = $metaDesc;
        } else {
            $header = $template['header'];
            $header = str_replace('{title}', $title, $header);
            $header = str_replace('{lang}', $lang, $header);
            $header = str_replace('{meta_description}', $metaDesc, $header);
            $nav->setHeader($header);
        }
        $nav->setHeader($nav->getHeader() . Translator::tlbuttonPop());
        if ($settings->getSetting('debug', 0)) {
            $session['debugstart'] = microtime();
        }
    }

    public static function popupHeader(...$args): void
    {
        $nav = Nav::getInstance();

        Translator::translatorSetup();
        Template::prepareTemplate();
        $template = Template::getInstance()->getTemplate();

        HookHandler::hook('header-popup');

        $arguments = func_get_args();
        if (!$arguments || count($arguments) === 0) {
            $arguments = ['Legend of the Green Dragon'];
        }
        $title = Translator::sprintfTranslate(...$arguments);
        $title = HolidayText::holidayize($title, 'title');

        $settings = Settings::getInstance();
        $lang     = defined('LANGUAGE') ? LANGUAGE : $settings->getSetting('defaultlanguage', 'en');
        $metaDesc = $settings->getSetting('meta_description', 'A browser game using the Legend of the Green Dragon Engine');

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
        $nav->setHeader($header);
    }
}
