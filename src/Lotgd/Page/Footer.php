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
use Lotgd\Realtime;
use Lotgd\Realtime\Poll;

class Footer
{
    /**
     * End the request with the full page.
     *
     * Builds the page state (which runs the page-end hooks), renders it,
     * stores it for badnav.php, saves the user and exits.
     */
    public static function pageFooter(bool $saveuser = true): void
    {
        global $session;

        $state = self::buildPageState();
        $browser_output = PageRenderer::render($state);

        // Decided before saveUser(), which reduces the session user. null:
        // a full page; false: partial was asked for but this page cannot be
        // swapped in, so the player is sent to the stored page instead.
        $partial = null;
        if (PartialMode::isActive()) {
            $clientHead = $_SERVER[PartialRenderer::REQUEST_HEAD_SERVER_KEY] ?? null;
            $partial = PartialRenderer::canRender($state, is_string($clientHead) ? $clientHead : null)
                && PartialMode::skinSupportsRegions()
                && PartialMode::isEnabledFor($session['user'] ?? [])
                ? PartialRenderer::render($state, (int) ($session['user']['page_revision'] ?? 0))
                : false;
        }

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

        if ($partial === false) {
            // badnav.php shows the page just stored; the URL does not run twice.
            PartialMode::sendHeader('HX-Redirect: badnav.php');
            exit();
        }
        if ($partial !== null) {
            PartialMode::sendHeader('Vary: HX-Request');
            foreach ($partial['headers'] as $name => $value) {
                PartialMode::sendHeader($name . ': ' . $value);
            }
            echo $partial['body'];
            exit();
        }

        echo $browser_output;
        exit();
    }

    /**
     * Assemble everything the full page consists of.
     *
     * This is where the page end's side effects happen, exactly once: the
     * built navigation is registered in allowednavs, the footer, favicon,
     * MOTD-link and stats hooks run, the MOTD check updates the session.
     * Renderers only read the returned state.
     */
    public static function buildPageState(): PageState
    {
        global $session;
        $page      = PageSingleton::getInstance();
        $settings  = Settings::getInstance();
        $template  = Template::getInstance()->getTemplate();

        $scriptName = PhpGenericEnvironment::getScriptName();
        // paypal_below is a first-class template variable; initialize an empty default
        // at footer start so both legacy and Twig rendering paths always see the token.
        PageParts::$twigVars['paypal_below'] = '';

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

        // Before the regions are built: what they show is at least this new.
        $realtime = Realtime::enabledFor($session['user'] ?? []);
        $realtimeMeta = '';
        if ($realtime) {
            $acctid = (int) ($session['user']['acctid'] ?? 0);
            $realtimeMeta = '<meta name="lotgd-realtime" content="async/poll.php|'
                . max(5, (int) $settings->getSetting('realtime_poll_seconds', 10)) . '">'
                . '<meta name="lotgd-event-cursor" content="' . Poll::startCursor() . '">'
                . '<meta name="lotgd-region-versions" content="' . htmlspecialchars((string) json_encode([
                    'mail' => Poll::regionVersion('mail', $acctid),
                    'stats' => Poll::regionVersion('stats', $acctid),
                ]), ENT_QUOTES, 'UTF-8') . '">';
        }

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
            $conn = Database::getDoctrineConnection();
            $row = $conn->executeQuery($sql)->fetchAssociative();
            $headscript = '';
            if (
                is_array($row) && isset($session['user']['lastmotd']) &&
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
                    require_once 'async/setup.php';
                    // The per-page values the polling script starts from. A
                    // partial answer carries them, since it has no <head>.
                    $asyncState = [
                        'commentSection' => (string) ($session['last_comment_section'] ?? ''),
                        'lastCommentId' => (int) ($session['lastcommentid'] ?? 0),
                        'lastUnreadMailId' => (int) ($lastUnreadMailId ?? 0),
                        'lastUnreadMailCount' => (int) ($lastUnreadMailCount ?? 0),
                    ];
                }
            }
        } else {
            $headscript     = '';
        }

        // The partial client binds the access keys itself, from the links in
        // the navigation region, so its quick keys follow every swap.
        $partialClient = PartialMode::clientActive($session['user'] ?? []);
        if ($partialClient) {
            Output::requireVendorAsset('htmx', 'js');
            Output::requireVendorAsset('lotgd-partial', 'js');
            Output::addHeadMarkup(
                '<meta name="lotgd-page-revision" content="' . (int) ($session['user']['page_revision'] ?? 0) . '">'
            );
            if ($realtime) {
                Output::addHeadMarkup($realtimeMeta);
            }
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
        if ($partialClient) {
            $script = '';
        }

        // Resolve this once and keep it first-class in replacements.
        // If a template does not expose {paypal_below}, we append this finalized
        // markup to the paypal replacement instead of requiring template edits.
        $paypalBelow = PageParts::resolvePaypalBelowSlot('');
        $templateProvidesPaypalBelowSlot = strpos($header, '{paypal_below}') !== false
            || strpos($footer, '{paypal_below}') !== false;

        $palreplace = (strpos($footer, '{paypal}') || strpos($header, '{paypal}')) ? '{paypal}' : '{stats}';

        if (defined('DB_CHOSEN')) {
            list($header, $footer) = PageParts::buildPaypalDonationMarkup(
                $palreplace,
                $header,
                $footer,
                $settings,
                $page->getLogdVersion(),
                $paypalBelow,
                !$templateProvidesPaypalBelowSlot
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
            // Dedicated slot below PayPal donation markup. Keep this separate from
            // {paypal} so one-pass token replacement and Twig rendering stay aligned.
            'paypal_below' => $paypalBelow,
        ];
        if (TwigTemplate::isActive()) {
            PageParts::$twigVars = array_merge(PageParts::$twigVars, $replacements);
        }

        list($header, $footer) = PageParts::replaceHeaderFooterTokens($header, $footer, $replacements);

        Translator::getInstance()->setSchema();

        $content = $output->getOutput();
        $isTwig = TwigTemplate::isActive();
        if ($isTwig) {
            PageParts::$twigVars = array_merge(PageParts::$twigVars, [
                'header' => $header,
                'footer' => $footer,
                'content' => $content,
                'template_path' => TwigTemplate::getPath(),
            ]);
        } else {
            $footer = preg_replace('/{[^} \t\n\r]*}/i', '', $footer);
            $header = PageParts::stripAdPlaceholders($header);
        }
        $navInstance->setHeader($header);

        $headSignature = PartialRenderer::headSignature(PageParts::$twigVars);
        if ($partialClient && $isTwig) {
            // After the signature, which leaves lotgd-* meta tags out anyway.
            PageParts::$twigVars['headscript_mid'] = (PageParts::$twigVars['headscript_mid'] ?? '')
                . '<meta name="lotgd-head" content="' . $headSignature . '">';
        }

        return new PageState(
            PageState::TEMPLATE_PAGE,
            $isTwig,
            $header,
            $footer,
            $content,
            $builtnavs,
            $statsOutput,
            $quickKeys,
            PageParts::$twigVars,
            $headSignature,
            $asyncState ?? []
        );
    }

    /**
     * End the request with a popup window.
     */
    public static function popupFooter(): void
    {
        $state = self::buildPopupState();
        $browser_output = PageRenderer::render($state);

        Accounts::saveUser();
        session_write_close();
        echo $browser_output;
        exit();
    }

    /**
     * Assemble everything a popup consists of; see buildPageState().
     */
    public static function buildPopupState(): PageState
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
                require_once 'async/setup.php';
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

        $content = $maillink_add_after . $output->getOutput();
        $isTwig = TwigTemplate::isActive();
        if ($isTwig) {
            PageParts::$twigVars = array_merge(PageParts::$twigVars, [
                'header' => $header,
                'footer' => $footer,
                'content' => $content,
                'template_path' => TwigTemplate::getPath(),
            ]);
        } else {
            $footer = preg_replace('/{[^} \t\n\r]*}/i', '', $footer);
            $header = PageParts::stripAdPlaceholders($header);
        }
        $navInstance->setHeader($header);

        return new PageState(
            PageState::TEMPLATE_POPUP,
            $isTwig,
            $header,
            $footer,
            $content,
            templateVars: PageParts::$twigVars
        );
    }
}
