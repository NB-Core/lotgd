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
use Lotgd\Security\Escape;

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
            $nav->setHeader(self::fillHeadPlaceholders($template['header'], $title, $lang, $metaDesc));
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

        $nav->setHeader(self::fillHeadPlaceholders($template['popuphead'], $title, $lang, $metaDesc));
    }

    /**
     * Fill a legacy template's head block.
     *
     * One method for the page head and the popup head, which carried the same
     * three replacements twice, and public so it can be asked directly: the
     * escaping below is the kind of thing that has to be provable, and driving
     * a whole page header to prove it would need Settings, Nav, the translator
     * and the module hooks.
     *
     * `{lang}` and `{meta_description}` land **inside quoted attributes**, and
     * both come from settings an operator edits in configuration.php. Without
     * escaping, a description containing a double quote closes the attribute,
     * and `"><script>...</script>` becomes stored markup on every page of every
     * legacy theme. Measured before this was added, on the real jade head: the
     * script tag reached the page. The Twig themes were never exposed -- Twig
     * escapes `{{ meta_description }}` by default, which is why only this path
     * needed it. Reported by Codex.
     *
     * `{title}` is not escaped here. It arrives already through
     * Sanitize::sanitize(), whose output is the game's own colour markup, and
     * no bundled template puts it inside an attribute -- it is element content
     * everywhere it appears. Escaping it here would render that markup as
     * literal text.
     */
    public static function fillHeadPlaceholders(
        string $head,
        string $title,
        string $lang,
        string $metaDescription
    ): string {
        $head = str_replace('{title}', $title, $head);
        $head = str_replace('{lang}', Escape::html($lang), $head);

        return str_replace('{meta_description}', Escape::html($metaDescription), $head);
    }
}
