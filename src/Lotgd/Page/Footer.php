<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\Settings;
use Lotgd\PageParts;
use Lotgd\Template;
use Lotgd\TwigTemplate;
use Lotgd\Buffs;
use Lotgd\Nav;
use Lotgd\Accounts;
use Lotgd\MySQL\Database;
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Translator;
use Lotgd\PhpGenericEnvironment;
use Lotgd\Page as PageSingleton;

class Footer
{
    public static function pageFooter(bool $saveuser = true): void
    {
        global $session;
        $page      = PageSingleton::getInstance();
        $settings  = Settings::getInstance();
        $template  = Template::getInstance()->getTemplate();

        $scriptName = PhpGenericEnvironment::getScriptName();

        $navInstance = Nav::getInstance();
        $header = $navInstance->getHeader();
        if (TwigTemplate::isActive()) {
            $footer = '';
        } else {
            $footer = $template['footer'];
        }

        $script = ScriptName::current();
        list($header, $footer) = PageParts::applyFooterHooks($header, $footer, $script);

        $builtnavs = Nav::buildNavs();

        Buffs::restoreBuffFields();
        Buffs::calculateBuffFields();

        Translator::getInstance()->setSchema('common');

        $statsOutput = PageParts::charStats();

        Buffs::restoreBuffFields();

        $defaultFaviconLink =
            "<link rel=\"shortcut icon\" HREF=\"/images/favicon/favicon.ico\" TYPE=\"image/x-icon\"/>" .
            "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"/images/favicon/apple-touch-icon.png\">" .
            "<link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"/images/favicon/favicon-32x32.png\">" .
            "<link rel=\"icon\" type=\"image/png\" sizes=\"16x16\" href=\"/images/favicon/favicon-16x16.png\">" .
            "<link rel=\"manifest\" href=\"/images/favicon/site.webmanifest\">";
        $pre_headscript = $defaultFaviconLink;

        if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
            $sql     = 'SELECT motddate FROM ' . Database::prefix('motd') . ' ORDER BY motditem DESC LIMIT 1';
            $result  = Database::query($sql);
            $row     = Database::fetchAssoc($result);
            $headscript = '';
            if (
                Database::numRows($result) > 0 && isset($session['user']['lastmotd']) &&
                ($row['motddate'] > $session['user']['lastmotd']) &&
                (!isset(PageParts::$noPopups[$scriptName]) || PageParts::$noPopups[$scriptName] != 1) &&
                $session['user']['loggedin']
            ) {
                if ($settings->getSetting('forcedmotdpopup', 0)) {
                    $headscript .= PageParts::popup('motd.php');
                }
                $session['needtoviewmotd'] = true;
            } else {
                $session['needtoviewmotd'] = false;
            }
            $favicon = ['favicon-link' => $defaultFaviconLink];
            $favicon        = HookHandler::hook('pageparts-favicon', $favicon);
            $pre_headscript = PageParts::canonicalLink() . $favicon['favicon-link'];
            if ($settings->getSetting('ajax', 1) == 1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
                if (file_exists('async/setup.php')) {
                    require 'async/setup.php';
                }
            }
        } else {
            $headscript     = '';
        }

        $header = PageParts::insertHeadScript($header, $pre_headscript, $headscript);

        $script = '';
        if (!isset($session['user']['name'])) {
            $session['user']['name'] = '';
        }
        if (!isset($session['user']['login'])) {
            $session['user']['login'] = '';
        }

        $quickKeys = Nav::getQuickKeys();
        $script = "<script type='text/javascript' charset='UTF-8'>\n";
        $script .= "document.addEventListener('keypress', event => {\n";
        $script .= "    const char = String.fromCharCode(event.charCode || event.keyCode).toUpperCase();\n";
        $script .= "    const target = event.target;\n";
        $script .= "    const isInput = ['INPUT', 'TEXTAREA'].includes(target.nodeName);\n";
        $script .= "    if (isInput || event.altKey || event.ctrlKey || event.metaKey) return;\n\n";

        // Create the key-action mapping
        $script .= "    const quickLinks = {\n";
        $entries = [];
        foreach ($quickKeys as $key => $val) {
            $key = strtoupper((string)$key);
            $entries[] = "        '{$key}': () => { {$val} }";
        }
        $script .= implode(",\n", $entries) . "\n";
        $script .= "    };\n\n";

        // Execution block
        $script .= "    if (quickLinks[char]) {\n";
        $script .= "        quickLinks[char]();\n";
        $script .= "        event.preventDefault();\n";
        $script .= "    }\n";
        $script .= "});\n";
        $script .= "</script>\n";

        $palreplace = (strpos($footer, '{paypal}') || strpos($header, '{paypal}')) ? '{paypal}' : '{stats}';

        if (defined('DB_CHOSEN')) {
            list($header, $footer) = PageParts::buildPaypalDonationMarkup(
                $palreplace,
                $header,
                $footer,
                $settings,
                $page->getLogdVersion()
            );
        }

        list($header, $footer) = PageParts::generateNavigationOutput($header, $footer, $builtnavs);
        if (TwigTemplate::isActive()) {
            PageParts::$twigVars['nav']      = $builtnavs;
            PageParts::$twigVars['navad']    = '';
            PageParts::$twigVars['verticalad'] = '';
            PageParts::$twigVars['bodyad']   = '';
        }

        if (defined('DB_CHOSEN') && DB_CHOSEN) {
            $motd_link = PageParts::motdLink();
        } else {
            $motd_link = '';
        }
        $motd_link = HookHandler::hook('motd-link', ['link' => $motd_link]);
        $motd_link = $motd_link['link'];

        list($header, $footer) = PageParts::assembleMailLink($header, $footer);
        list($header, $footer) = PageParts::assemblePetitionLink($header, $footer);
        list($header, $footer) = PageParts::assemblePetitionDisplay($header, $footer);
        $sourcelink = 'source.php?url=' . preg_replace('/[?].*/', '', (PhpGenericEnvironment::getRequestUri()));

        $output = Output::getInstance();

        $page->antiCheatProtection();

        $replacements = [
            'stats'   => $statsOutput,
            'script'  => $script,
            'motd'    => $motd_link,
            'source'  => "<a href='$sourcelink' onclick=\"" . PageParts::popup($sourcelink) . ";return false;\" target='_blank'>" . Translator::translateInline('View PHP Source') . '</a>',
            'version' => 'Version: ' . $page->getLogdVersion(),
            'pagegen' => PageParts::computePageGenerationStats(PhpGenericEnvironment::getPageStartTime()),
            'copyright' => $page->{$page->getV()}(),
        ];
        if (TwigTemplate::isActive()) {
            PageParts::$twigVars = array_merge(PageParts::$twigVars, $replacements);
        }

        list($header, $footer) = PageParts::replaceHeaderFooterTokens($header, $footer, $replacements);

        Translator::getInstance()->setSchema();

        if (TwigTemplate::isActive()) {
            PageParts::$twigVars = array_merge(PageParts::$twigVars, [
                'header' => $header,
                'footer' => $footer,
                'content' => $output->getOutput(),
                'template_path' => TwigTemplate::getPath(),
            ]);
            $browser_output = TwigTemplate::render('page.twig', PageParts::$twigVars);
        } else {
            $footer = preg_replace('/{[^} \t\n\r]*}/i', '', $footer);
            $header = PageParts::stripAdPlaceholders($header);
            $browser_output = $header . ($output->getOutput()) . $footer;
        }
        $navInstance->setHeader($header);
        if (!isset($session['user']['gensize'])) {
            $session['user']['gensize'] = 0;
        }
        $session['user']['gensize'] += strlen($browser_output);
        $session['output'] = $browser_output;
        if ($saveuser === true) {
            Accounts::saveUser();
        }
        unset($session['output']);
        session_write_close();
        echo $browser_output;
        exit();
    }

    public static function popupFooter(): void
    {
        global $session;
        $page     = PageSingleton::getInstance();
        $template = Template::getInstance()->getTemplate();
        $navInstance = Nav::getInstance();
        $header      = $navInstance->getHeader();

        $settings  = Settings::getInstance();
        $headscript = '';
        if (TwigTemplate::isActive()) {
            $footer = '';
        } else {
            $footer = $template['popupfoot'];
        }
        $pre_headscript   = PageParts::canonicalLink();
        $maillink_add_after = '';
        if ($settings->getSetting('ajax', 1) == 1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
            if (file_exists('async/setup.php')) {
                require 'async/setup.php';
            }
        }

        list($header, $footer) = PageParts::applyPopupFooterHooks($header, $footer);

        $output = Output::getInstance();

        if (isset($session['user']['acctid']) && $session['user']['acctid'] > 0 && $session['user']['loggedin']) {
            if ($settings->getSetting('ajax', 1) == 1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
                if (file_exists('async/maillink.php')) {
                    require 'async/maillink.php';
                }
            } else {
                $maillink_add_after = '';
            }
        }
        $header = PageParts::insertHeadScript($header, $pre_headscript, $headscript);

        $page->antiCheatProtection();
        $copyright = $page->getCopyright();
        list($header, $footer) = PageParts::replaceHeaderFooterTokens($header, $footer, [
            'script' => '',
            'mail'   => (strpos($header, '{mail}') !== false || strpos($footer, '{mail}') !== false)
                ? PageParts::mailLink()
                : '',
            'copyright' => $copyright,
        ]);

        if (TwigTemplate::isActive()) {
            PageParts::$twigVars = array_merge(PageParts::$twigVars, [
                'header' => $header,
                'footer' => $footer,
                'content' => $maillink_add_after . $output->getOutput(),
                'template_path' => TwigTemplate::getPath(),
            ]);
            $browser_output = TwigTemplate::render('popup.twig', PageParts::$twigVars);
            $navInstance->setHeader($header);
            Accounts::saveUser();
            session_write_close();
            echo $browser_output;
            exit();
        }

        $footer = preg_replace('/{[^} \t\n\r]*}/i', '', $footer);
        $header = PageParts::stripAdPlaceholders($header);

        $browser_output = $header . $maillink_add_after . ($output->getOutput()) . $footer;
        $navInstance->setHeader($header);
        Accounts::saveUser();
        session_write_close();
        echo $browser_output;
        exit();
    }
}
