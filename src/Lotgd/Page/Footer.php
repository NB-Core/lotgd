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
use Lotgd\Nav;
use Lotgd\Accounts;
use Lotgd\MySQL\Database;

class Footer
{
	public static function pageFooter(bool $saveuser = true): void
	{
		global $output, $header, $nav, $session, $REMOTE_ADDR, $REQUEST_URI, $pagestarttime,
			$template, $y2, $z2, $logd_version, $copyright, $SCRIPT_NAME, $footer,
			$settings;

		$z = $y2 ^ $z2;
		if (TwigTemplate::isActive()) {
			$footer = '';
			$header = $header ?? '';
		} else {
			$footer = $template['footer'];
		}

		$script = !empty($SCRIPT_NAME) ? substr($SCRIPT_NAME, 0, strpos($SCRIPT_NAME, '.')) : '';
		list($header, $footer) = PageParts::applyFooterHooks($header, $footer, $script);

		$builtnavs = Nav::buildNavs();

		Buffs::restoreBuffFields();
		Buffs::calculateBuffFields();

		Translator::tlschema('common');

		$statsOutput = PageParts::charStats();

		Buffs::restoreBuffFields();

		if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
			$sql     = 'SELECT motddate FROM ' . Database::prefix('motd') . ' ORDER BY motditem DESC LIMIT 1';
			$result  = Database::query($sql);
			$row     = Database::fetchAssoc($result);
			$headscript = '';
			if (
				Database::numRows($result) > 0 && isset($session['user']['lastmotd']) &&
				($row['motddate'] > $session['user']['lastmotd']) &&
				(!isset(PageParts::$noPopups[$SCRIPT_NAME]) || PageParts::$noPopups[$SCRIPT_NAME] != 1) &&
				$session['user']['loggedin']
			) {
				if (isset($settings) && $settings->getSetting('forcedmotdpopup', 0)) {
					$headscript .= PageParts::popup('motd.php');
				}
				$session['needtoviewmotd'] = true;
			} else {
				$session['needtoviewmotd'] = false;
			}
			$favicon = [
				'favicon-link' =>
				"<link rel=\"shortcut icon\" HREF=\"/images/favicon/favicon.ico\" TYPE=\"image/x-icon\"/>" .
				"<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"/images/favicon/apple-touch-icon.png\">" .
				"<link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"/images/favicon/favicon-32x32.png\">" .
				"<link rel=\"icon\" type=\"image/png\" sizes=\"16x16\" href=\"/images/favicon/favicon-16x16.png\">" .
				"<link rel=\"manifest\" href=\"/images/favicon/site.webmanifest\">",
			];
			$favicon        = modulehook('pageparts-favicon', $favicon);
			$pre_headscript = $favicon['favicon-link'];
			if (isset($settings) && $settings->getSetting('ajax', 1) == 1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
				if (file_exists('ext/ajax_base_setup.php')) {
					require 'ext/ajax_base_setup.php';
				}
			}
		} else {
			$pre_headscript = '';
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
		$script .= "document.onkeypress = function(e) {\n";
		$script .= "    var c, target, altKey, ctrlKey;\n";
		$script .= "    if (window.event) {\n";
		$script .= "        c = String.fromCharCode(window.event.keyCode).toUpperCase();\n";
		$script .= "        altKey = window.event.altKey;\n";
		$script .= "        ctrlKey = window.event.ctrlKey;\n";
		$script .= "        target = window.event.srcElement;\n";
		$script .= "    } else {\n";
		$script .= "        c = String.fromCharCode(e.charCode).toUpperCase();\n";
		$script .= "        altKey = e.altKey;\n";
		$script .= "        ctrlKey = e.ctrlKey;\n";
		$script .= "        target = e.originalTarget || e.target;\n";
		$script .= "    }\n";
		$script .= "    if (target.nodeName.toUpperCase() === 'INPUT' || target.nodeName.toUpperCase() === 'TEXTAREA' || altKey || ctrlKey) return;\n\n";

		// Build JS object mapping keys to actions
		$script .= "    var quickLinks = {\n";
		$entries = [];
		foreach ($quickKeys as $key => $val) {
			// $val is assumed to be a JS statement like `window.location = '...';`
			$key = strtoupper((string)$key);
			$entries[] = "        '{$key}': function() { {$val} }";
		}
		$script .= implode(",\n", $entries) . "\n";
		$script .= "    };\n\n";

		// Execution logic
		$script .= "    if (quickLinks[c]) {\n";
		$script .= "        quickLinks[c]();\n";
		$script .= "        return false;\n";
		$script .= "    }\n";
		$script .= "};\n";
		$script .= "</script>\n";

		$palreplace = (strpos($footer, '{paypal}') || strpos($header, '{paypal}')) ? '{paypal}' : '{stats}';

		list($header, $footer) = PageParts::buildPaypalDonationMarkup(
			$palreplace,
			$header,
			$footer,
			$settings ?? null,
			$logd_version
		);

		list($header, $footer) = PageParts::generateNavigationOutput($header, $footer, $builtnavs);
		if (TwigTemplate::isActive()) {
			PageParts::$twigVars['nav']      = $builtnavs;
			PageParts::$twigVars['navad']    = '';
			PageParts::$twigVars['verticalad'] = '';
			PageParts::$twigVars['bodyad']   = '';
		}

		$motd_link = PageParts::motdLink();
		$motd_link = modulehook('motd-link', ['link' => $motd_link]);
		$motd_link = $motd_link['link'];

		list($header, $footer) = PageParts::assembleMailLink($header, $footer);
		list($header, $footer) = PageParts::assemblePetitionLink($header, $footer);
		list($header, $footer) = PageParts::assemblePetitionDisplay($header, $footer);
		$sourcelink = 'source.php?url=' . preg_replace('/[?].*/', '', ($_SERVER['REQUEST_URI']));

		$replacements = [
			'stats'   => $statsOutput,
			'script'  => $script,
			'motd'    => $motd_link,
			'source'  => "<a href='$sourcelink' onclick=\"" . PageParts::popup($sourcelink) . ";return false;\" target='_blank'>" . Translator::translateInline('View PHP Source') . '</a>',
			'version' => "Version: $logd_version",
			'pagegen' => PageParts::computePageGenerationStats($pagestarttime),
			$z       => $$z,
		];
		if (TwigTemplate::isActive()) {
			PageParts::$twigVars = array_merge(PageParts::$twigVars, $replacements);
		}

		list($header, $footer) = PageParts::replaceHeaderFooterTokens($header, $footer, $replacements);

		Translator::tlschema();

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
		global $output, $header, $session, $y2, $z2, $copyright, $template;

		$headscript = '';
		if (TwigTemplate::isActive()) {
			$footer = '';
			$header = $header ?? '';
		} else {
			$footer = $template['popupfoot'];
		}
		$pre_headscript   = '';
		$maillink_add_after = '';
		if (getsetting('ajax', 1) == 1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
			if (file_exists('ext/ajax_base_setup.php')) {
				require 'ext/ajax_base_setup.php';
			}
		}

		list($header, $footer) = PageParts::applyPopupFooterHooks($header, $footer);

		if (isset($session['user']['acctid']) && $session['user']['acctid'] > 0 && $session['user']['loggedin']) {
			if (getsetting('ajax', 1) == 1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
				if (file_exists('ext/ajax_maillink.php')) {
					require 'ext/ajax_maillink.php';
				}
			} else {
				$maillink_add_after = '';
			}
		}
		$header = PageParts::insertHeadScript($header, $pre_headscript, $headscript);

		$z = $y2 ^ $z2;
		list($header, $footer) = PageParts::replaceHeaderFooterTokens($header, $footer, [
			'script' => '',
			'mail'   => (strpos($header, '{mail}') !== false || strpos($footer, '{mail}') !== false)
			? PageParts::mailLink()
			: '',
			$z       => $$z,
		]);

		if (TwigTemplate::isActive()) {
			PageParts::$twigVars = array_merge(PageParts::$twigVars, [
				'header' => $header,
				'footer' => $footer,
				'content' => $maillink_add_after . $output->getOutput(),
				'template_path' => TwigTemplate::getPath(),
			]);
			$browser_output = TwigTemplate::render('popup.twig', PageParts::$twigVars);
			Accounts::saveUser();
			session_write_close();
			echo $browser_output;
			exit();
		}

		$footer = preg_replace('/{[^} \t\n\r]*}/i', '', $footer);
		$header = PageParts::stripAdPlaceholders($header);

		$browser_output = $header . $maillink_add_after . ($output->getOutput()) . $footer;
		Accounts::saveUser();
		session_write_close();
		echo $browser_output;
		exit();
	}
}
