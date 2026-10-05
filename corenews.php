<?php

declare(strict_types=1);

use Lotgd\Nav\SuperuserNav;
use Lotgd\Output;
use Lotgd\Page;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Security\Escape;
use Lotgd\Settings;
use Lotgd\SuAccess;
use Lotgd\Translator;
use Lotgd\Update\UpstreamVersion;

require __DIR__ . "/common.php";

$output = Output::getInstance();
$settings = Settings::getInstance();

Translator::getInstance()->setSchema("corenews");
SuAccess::check(SU_MEGAUSER);

Header::pageHeader("Core News");

SuperuserNav::render();

$installed = Page::getInstance()->getLogdVersion();
$upstream = new UpstreamVersion($settings);

/**
 * A link that opens on GitHub; the URL is built by UpstreamVersion.
 */
$link = static function (string $url, string $label): string {
    return "<a href='" . Escape::html($url) . "' target='_blank' rel='noopener noreferrer'>" . Escape::html($label) . "</a>";
};

$output->output("`^Installed version:`0 %s`n`n", $installed);

if (! $upstream->enabled()) {
    $output->output("`4The version check is turned off. It can be turned on in the game settings (Game Setup).`0`n");
} else {
    $output->output("`b`@Latest release`0`b`n");
    $release = $upstream->latestRelease();
    if ($release === null) {
        $output->output("`4GitHub could not be reached. The check is tried again within the hour.`0`n");
    } else {
        $output->output("`^Release:`0 %s`n", $release['name']);
        $output->output("`^Tag:`0 %s`n", $release['tag']);
        if ($release['published'] !== '') {
            $output->output("`^Published:`0 %s`n", $release['published']);
        }
        $output->output("`^Link:`0 ");
        $output->rawOutput($link($release['url'], $release['url']));
        $output->outputNotl("`n");

        switch (UpstreamVersion::status($installed, $release['tag'])) {
            case UpstreamVersion::STATUS_UPDATE_AVAILABLE:
                $output->output("`@An update is available: %s.`0`n", $release['tag']);
                break;
            case UpstreamVersion::STATUS_CURRENT:
                $output->output("`2This installation is up to date.`0`n");
                break;
            case UpstreamVersion::STATUS_AHEAD:
                $output->output("`2This installation is a development build, newer than the latest release.`0`n");
                break;
            default:
                $output->output("`4The installed version could not be compared with the release.`0`n");
        }

        $notes = $upstream->releaseNotes();
        if ($notes !== null && trim($notes) !== '') {
            $output->output("`n`^Release notes:`0`n");
            $output->rawOutput("<div class='corenews-notes'>" . nl2br(Escape::html($notes)) . "</div>");
        }
    }

    $output->output("`n`b`@Development version (master)`0`b`n");
    $master = $upstream->master();
    if ($master['version'] === null && $master['head'] === null) {
        $output->output("`4GitHub could not be reached.`0`n");
    } else {
        if ($master['version'] !== null) {
            switch (UpstreamVersion::status($installed, $master['version'])) {
                case UpstreamVersion::STATUS_UPDATE_AVAILABLE:
                    $output->output("`^Development version %s is on master (installed: %s).`0`n", $master['version'], $installed);
                    break;
                case UpstreamVersion::STATUS_CURRENT:
                    $output->output("`2This installation matches the development version %s.`0`n", $master['version']);
                    break;
                default:
                    $output->output("`^Development version on master:`0 %s`n", $master['version']);
            }
        }
        if ($master['head'] !== null) {
            $head = $master['head'];
            $output->output("`^Last change on master:`0 %s, %s (", $head['date'], $head['title']);
            $output->rawOutput($link($head['url'], $head['sha']));
            $output->outputNotl(")`n");
        }
    }
}

Footer::pageFooter();
