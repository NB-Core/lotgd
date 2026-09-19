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

        $title = self::headerTitle(func_get_args());
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

        $title = self::headerTitle(func_get_args());

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
     * The title both head paths render.
     *
     * One method because the two had drifted: pageHeader() ran the translated
     * title through Sanitize::sanitize() and popupHeader() did not, so a title
     * carrying a colour code -- a backtick-4 for red, and a title is one of the
     * places the game writes them -- reached a popup's <title> as those two
     * literal characters, where a page's did not. Reported by Copilot.
     *
     * What that sanitiser does is strip colour codes; it is not an escaper and
     * confers no HTML safety. See fillHeadPlaceholders() for what does.
     *
     * @param list<mixed> $arguments sprintf-style: a format string, then values
     */
    public static function headerTitle(array $arguments): string
    {
        if ($arguments === []) {
            $arguments = ['Legend of the Green Dragon'];
        }

        $title = Translator::sprintfTranslate(...$arguments);

        return Sanitize::sanitize(HolidayText::holidayize($title, 'title'));
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
     * `{title}` is deliberately *not* escaped, and the reason is not the one it
     * is easy to assume: Sanitize::sanitize() strips colour codes and is not an
     * escaper, so it buys nothing here. Two things do. It is element content in
     * every bundled template -- `<title>`, a `<span>`, a `<td>` -- and never an
     * attribute, so there is no quote to close. And titles legitimately carry
     * entities: pages/clan/detail.php:121 asks for
     * `"Clan Membership for %s &lt;%s&gt;"`, which escaping would print as the
     * literal `&lt;`. So this is a known limit, not an oversight -- a title is
     * assembled by core and by modules, and one built from something a player
     * controls would need escaping at that call site, where what the value is
     * is still known.
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
